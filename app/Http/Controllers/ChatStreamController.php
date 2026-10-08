<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiUsageLimitException;
use App\Enums\AiSource;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversation\ConversationService;
use App\Services\Message\MessageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events streaming for AI message generation.
 *
 * Thin HTTP layer only: validation via Form Requests, authorization before
 * headers are sent (proper 403 status), then delegation to MessageService
 * which owns persistence, rate limiting, and AI orchestration. The browser
 * receives an application-level event stream — never provider payloads.
 *
 * Event format (one JSON object per `data:` frame):
 * - `{"type":"meta",...}` — ids for reconciliation
 * - `{"type":"delta","text":"..."}` — incremental assistant text
 * - `{"type":"done",...}` — stream completed, message persisted
 * - `{"type":"error","message":"..."}` — safe user-facing failure
 */
class ChatStreamController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * Stream the assistant reply for an existing conversation.
     *
     * Persists the user message first (same guarantee as the synchronous
     * flow), then streams the assistant deltas, persisting the final
     * assistant message exactly once when the stream completes.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): StreamedResponse
    {
        $this->authorize('view', $conversation);

        $content = (string) $request->validated('content');
        $user = $request->user();

        return $this->streamResponse(function () use ($user, $conversation, $content) {
            $userMessage = $this->messages->createUserMessage($user, $conversation, $content, AiSource::Web);

            $this->sendEvent(['type' => 'meta', 'user_message_id' => (string) $userMessage->getKey()]);

            $generator = $this->messages->streamReply($user, $conversation, AiSource::Web);

            foreach ($generator as $delta) {
                if (connection_aborted()) {
                    break;
                }

                $this->sendEvent(['type' => 'delta', 'text' => $delta]);
            }

            if (connection_aborted()) {
                return;
            }

            $assistant = $generator->getReturn();

            $this->sendEvent([
                'type' => 'done',
                'assistant_message_id' => (string) $assistant->getKey(),
            ]);
        });
    }

    /**
     * Stream the first exchange for a brand-new conversation.
     *
     * Creates the conversation (plus its first user message) in one atomic
     * operation, then streams exactly like `store()`. The `done` event
     * carries the destination URL so the create page can navigate to the
     * persisted conversation.
     */
    public function storeNew(StoreMessageRequest $request): StreamedResponse
    {
        $content = (string) $request->validated('content');
        $user = $request->user();

        return $this->streamResponse(function () use ($user, $content) {
            $conversation = $this->conversations->startFor($user, $content, AiSource::Web);

            $this->sendEvent([
                'type' => 'meta',
                'conversation_id' => (string) $conversation->getKey(),
            ]);

            $generator = $this->messages->streamReply($user, $conversation, AiSource::Web);

            foreach ($generator as $delta) {
                if (connection_aborted()) {
                    break;
                }

                $this->sendEvent(['type' => 'delta', 'text' => $delta]);
            }

            if (connection_aborted()) {
                return;
            }

            $assistant = $generator->getReturn();

            $this->sendEvent([
                'type' => 'done',
                'conversation_id' => (string) $conversation->getKey(),
                'assistant_message_id' => (string) $assistant->getKey(),
                'redirect_url' => route('conversations.show', $conversation),
            ]);
        });
    }

    /**
     * Continue the latest interrupted (partial) assistant reply.
     *
     * Streams only the newly generated text; the browser appends it to the
     * existing partial bubble. The message is updated exactly once when the
     * continuation finishes.
     */
    public function continue(Request $request, Conversation $conversation): StreamedResponse
    {
        $this->authorize('view', $conversation);

        $user = $request->user();

        return $this->streamResponse(function () use ($user, $conversation) {
            $generator = $this->messages->continueReply($user, $conversation, AiSource::Web);

            $this->sendEvent([
                'type' => 'meta',
                'continued' => true,
            ]);

            foreach ($generator as $delta) {
                if (connection_aborted()) {
                    break;
                }

                $this->sendEvent(['type' => 'delta', 'text' => $delta]);
            }

            if (connection_aborted()) {
                return;
            }

            $assistant = $generator->getReturn();

            $this->sendEvent([
                'type' => 'done',
                'assistant_message_id' => (string) $assistant->getKey(),
                'continued' => true,
            ]);
        });
    }

    /**
     * Regenerate the assistant reply for one message and stream it.
     *
     * Accepts a user message (its reply is regenerated) or an assistant
     * message (regenerated from its preceding user message). Later history
     * is truncated first so no stale reply survives next to its
     * replacement; the `meta` event carries the removed ids so the browser
     * can drop the same nodes optimistically.
     */
    public function regenerate(Request $request, Conversation $conversation, Message $message): StreamedResponse
    {
        $this->authorize('view', $conversation);

        if ($message->conversation_id !== $conversation->getKey()) {
            abort(404);
        }

        $user = $request->user();
        $removed = $this->messages->idsRemovedByRegeneration($user, $conversation, $message);

        return $this->streamResponse(function () use ($user, $conversation, $message, $removed) {
            $this->sendEvent([
                'type' => 'meta',
                'regenerated' => true,
                'removed_message_ids' => $removed,
            ]);

            $generator = $this->messages->regenerateReply($user, $conversation, $message, AiSource::Web);

            foreach ($generator as $delta) {
                if (connection_aborted()) {
                    break;
                }

                $this->sendEvent(['type' => 'delta', 'text' => $delta]);
            }

            if (connection_aborted()) {
                return;
            }

            $assistant = $generator->getReturn();

            $this->sendEvent([
                'type' => 'done',
                'assistant_message_id' => (string) $assistant->getKey(),
                'regenerated' => true,
            ]);
        });
    }

    /**
     * Build the SSE streamed response around a generator closure.
     *
     * Progressive delivery requires defeating PHP's output buffering: small
     * echoes otherwise sit in the output buffer until the script ends and
     * the browser receives everything at once (cursor visible, text only at
     * the end). Each frame is therefore flushed immediately. Buffer handling
     * is skipped under PHPUnit so the test capture buffer survives.
     *
     * Authorization already happened before headers, so any AiException or
     * unexpected failure inside the stream becomes a safe SSE `error`
     * event — never keys, headers, payloads, or stack traces.
     */
    private function streamResponse(callable $producer): StreamedResponse
    {
        return response()->stream(function () use ($producer) {
            $this->beginUnbufferedStream();

            try {
                $producer();
            } catch (AiException $e) {
                report($e);

                $error = ['type' => 'error', 'message' => $e->userMessage()];

                // Application usage-limit failures carry a machine-readable
                // code (plus remaining cooldown) so the chat UI can render
                // its dedicated limit state. Provider failures (including
                // upstream HTTP 429) never carry this code and keep the
                // generic error path.
                if ($e instanceof AiUsageLimitException) {
                    $error['code'] = 'usage_limit';
                    $error['retry_after'] = $e->retryAfterSeconds();
                }

                $this->sendEvent($error);
            } catch (Throwable $e) {
                report($e);

                $this->sendEvent(['type' => 'error', 'message' => 'حدث خطأ غير متوقع. حاول مرة أخرى.']);
            }

            $this->flushStream();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Prepare the PHP process for a long-lived incremental response: drop
     * any output-buffering layers so each echo reaches the web server
     * immediately, keep running after a client abort (so a partial reply
     * can still be persisted), and remove the execution time limit.
     */
    private function beginUnbufferedStream(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        ignore_user_abort(true);

        set_time_limit(0);
    }

    private function sendEvent(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        $this->flushStream();
    }

    private function flushStream(): void
    {
        // Flush the SSE frame immediately in production: first PHP's own
        // output buffers (flush() alone cannot empty those), then the web
        // server buffer. Under PHPUnit the streamed content is captured by
        // output buffering instead, so skip flushing there to keep the
        // capture buffer intact.
        if (app()->runningUnitTests()) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
