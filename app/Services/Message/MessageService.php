<?php

namespace App\Services\Message;

use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiUsageLimitException;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiContextService;
use App\Services\AI\AiMemoryService;
use App\Services\AI\AiRequestLogger;
use App\Services\AI\AiService;
use App\Services\AI\AiUsageService;
use App\Services\Audit\AuditLogService;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class MessageService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly AiUsageService $usage,
        private readonly AiRequestLogger $requests,
        private readonly AuditLogService $audit,
        private readonly AiContextService $context,
        private readonly AiMemoryService $memories,
    ) {}

    /**
     * Number of messages loaded per history page.
     *
     * The Web Chat never loads the whole conversation at once: the initial
     * paint shows only this many latest messages and older ones are fetched
     * progressively with a cursor (see latestPageFor/olderPageFor). The AI
     * context window is bounded separately in AiContextService and is
     * unaffected by this value.
     */
    public const HISTORY_PAGE_SIZE = 30;

    /**
     * Retrieve a conversation's messages in chronological order after
     * verifying the user is authorised to access the conversation.
     */
    public function listFor(User $user, Conversation $conversation): Collection
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Retrieve the latest page of a conversation's history for the initial
     * chat paint: at most HISTORY_PAGE_SIZE messages, oldest first.
     *
     * Cursor-based (ULID `id` ordering, which is time-sortable), never
     * OFFSET: the extra probe row decides `hasMore` without a COUNT query.
     *
     * @return array{messages: Collection<int, Message>, hasMore: bool, oldestCursor: ?string}
     */
    public function latestPageFor(User $user, Conversation $conversation, int $limit = self::HISTORY_PAGE_SIZE): array
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $this->historyPage($conversation, null, $limit);
    }

    /**
     * Retrieve the next older page before the given cursor message id.
     *
     * Conceptually: WHERE conversation_id = ? AND id < ? ORDER BY id DESC
     * LIMIT n. Returned oldest-first for display; `oldestCursor` is the
     * cursor for the following page, `hasMore` is false when exhausted.
     *
     * @return array{messages: Collection<int, Message>, hasMore: bool, oldestCursor: ?string}
     */
    public function olderPageFor(User $user, Conversation $conversation, ?string $beforeId, int $limit = self::HISTORY_PAGE_SIZE): array
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $this->historyPage($conversation, $beforeId, $limit);
    }

    /**
     * Shared cursor page behind latestPageFor() and olderPageFor().
     *
     * @return array{messages: Collection<int, Message>, hasMore: bool, oldestCursor: ?string}
     */
    private function historyPage(Conversation $conversation, ?string $beforeId, int $limit): array
    {
        $limit = max(1, min($limit, self::HISTORY_PAGE_SIZE));

        $rows = $conversation->messages()
            ->when(filled($beforeId), fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->reverse()->values();
        $oldest = $page->first();

        return [
            'messages' => $page,
            'hasMore' => $hasMore,
            'oldestCursor' => $oldest instanceof Message ? (string) $oldest->getKey() : null,
        ];
    }

    /**
     * Persist a user message on the given conversation after verifying the
     * user is authorised to access it. The conversation and role are always
     * determined by the server, never taken from the client.
     */
    public function createUserMessage(User $user, Conversation $conversation, string $content, AiSource $source = AiSource::Web): Message
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $message = $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
        ]);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::MESSAGE_SENT,
            'success',
            ['conversation_id' => (string) $conversation->getKey()],
            $message
        );

        return $message;
    }

    /**
     * Persist a user message and generate the assistant's reply.
     *
     * The user message is persisted BEFORE calling the external AI provider,
     * so a provider failure never loses it: the user message stays while no
     * assistant message is created. Only normalized messages cross the AI
     * boundary; this service never knows provider payloads or responses.
     *
     * @throws AiException
     */
    public function sendUserMessage(User $user, Conversation $conversation, string $content, AiSource $source = AiSource::Web): Message
    {
        $this->createUserMessage($user, $conversation, $content, $source);

        return $this->generateReply($user, $conversation, $source);
    }

    /**
     * Generate and persist the assistant's reply for the current
     * conversation history.
     *
     * Context (persona + memory + bounded recent history) is resolved
     * first; only the normalized result crosses the AI boundary.
     *
     * Application-level rate limiting runs here, at the single choke
     * point for assistant generation, keyed by the acting user — alongside
     * the separate application usage policy (window + cooldown). Upstream
     * provider limits surface as provider failures, never as usage blocks.
     *
     * @throws AiException
     */
    public function generateReply(User $user, Conversation $conversation, AiSource $source = AiSource::Web): Message
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $this->enforceGenerationRateLimit($user);
        $this->enforceUsagePolicy($user, $conversation, $source);

        $history = $this->context->build($user, $conversation);
        $aiRequest = $this->requests->start($user, $source, $conversation);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::AI_REQUEST_STARTED,
            'success',
            $this->aiMetadata($aiRequest->provider, $aiRequest->model, $conversation),
            $conversation
        );

        try {
            $reply = $this->ai->generate($history);
        } catch (AiException $e) {
            $this->failRequest($user, $source, $conversation, $aiRequest, $e);

            throw $e;
        }

        $assistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $reply,
        ]);

        $this->completeRequest($user, $source, $conversation, $aiRequest, $assistant, false);

        return $assistant;
    }

    /**
     * Stream the assistant's reply as incremental text deltas.
     *
     * Yields each upstream chunk immediately so the controller can forward
     * it to the browser. The final assistant message is persisted exactly
     * once, after the stream completes successfully — never per chunk.
     *
     * When the browser aborts mid-stream (Stop), the text received so far
     * is preserved as a `partial` assistant message that can be continued
     * later, instead of being lost. An abort before any text arrived keeps
     * the previous guarantee: no assistant message at all.
     *
     * The caller must have persisted the user message already (via
     * `createUserMessage`); history is read here so it includes it.
     *
     * @return Generator<int, string, mixed, Message>
     *
     * @throws AiException
     */
    public function streamReply(User $user, Conversation $conversation, AiSource $source = AiSource::Web): Generator
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $this->enforceGenerationRateLimit($user);
        $this->enforceUsagePolicy($user, $conversation, $source);

        $history = $this->context->build($user, $conversation);
        $aiRequest = $this->requests->start($user, $source, $conversation);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::AI_REQUEST_STARTED,
            'success',
            $this->aiMetadata($aiRequest->provider, $aiRequest->model, $conversation),
            $conversation
        );

        $text = '';

        try {
            foreach ($this->ai->stream($history) as $delta) {
                $text .= $delta;

                yield $delta;

                if (connection_aborted()) {
                    break;
                }
            }
        } catch (AiException $e) {
            $this->failRequest($user, $source, $conversation, $aiRequest, $e);

            throw $e;
        }

        if (trim($text) === '') {
            $failure = new AiInvalidResponseException('unknown');
            $this->failRequest($user, $source, $conversation, $aiRequest, $failure);

            throw $failure;
        }

        $aborted = connection_aborted();

        $assistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $text,
            'status' => $aborted ? MessageStatus::Partial : MessageStatus::Complete,
        ]);

        $this->completeRequest($user, $source, $conversation, $aiRequest, $assistant, $aborted);

        return $assistant;
    }

    /**
     * Continue an interrupted (partial) assistant reply.
     *
     * The partial message must be the latest message in the conversation;
     * its text is sent back as conversation context so the provider
     * continues from it, and only the newly streamed text is yielded. The
     * message is updated exactly once (single UPDATE, never per chunk) and
     * marked complete — or kept partial when aborted again.
     *
     * @return Generator<int, string, mixed, Message>
     *
     * @throws AiException
     */
    public function continueReply(User $user, Conversation $conversation, AiSource $source = AiSource::Web): Generator
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $this->enforceGenerationRateLimit($user);
        $this->enforceUsagePolicy($user, $conversation, $source);

        $messages = $this->listFor($user, $conversation);
        $last = $messages->last();

        if (! $last instanceof Message
            || $last->role !== MessageRole::Assistant
            || $last->status !== MessageStatus::Partial) {
            throw new AiProviderException('There is no interrupted assistant response to continue.', 'unknown');
        }

        $history = $this->context->build($user, $conversation);
        $aiRequest = $this->requests->start($user, $source, $conversation);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::AI_REQUEST_STARTED,
            'success',
            $this->aiMetadata($aiRequest->provider, $aiRequest->model, $conversation),
            $conversation
        );

        $append = '';

        try {
            foreach ($this->ai->stream($history) as $delta) {
                $append .= $delta;

                yield $delta;

                if (connection_aborted()) {
                    break;
                }
            }
        } catch (AiException $e) {
            $this->failRequest($user, $source, $conversation, $aiRequest, $e);

            throw $e;
        }

        if (trim($append) === '' && ! connection_aborted()) {
            $failure = new AiInvalidResponseException('unknown');
            $this->failRequest($user, $source, $conversation, $aiRequest, $failure);

            throw $failure;
        }

        $aborted = connection_aborted();

        $last->update([
            'content' => $last->content.$append,
            'status' => $aborted ? MessageStatus::Partial : MessageStatus::Complete,
        ]);

        $assistant = $last->refresh();

        $this->completeRequest($user, $source, $conversation, $aiRequest, $assistant, $aborted);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::MESSAGE_CONTINUED,
            'success',
            ['conversation_id' => (string) $conversation->getKey()],
            $assistant
        );

        return $assistant;
    }

    /**
     * Regenerate the assistant reply for one message and stream it.
     *
     * The given message may be a user message (its reply is regenerated)
     * or an assistant message (it is regenerated from its preceding user
     * message). The conversation is linear history, so everything after
     * the anchor user message is removed first — a stale reply must never
     * survive next to its replacement, and later exchanges depended on
     * the old context. The fresh reply streams exactly like a new one
     * (single create, abort preserves a partial).
     *
     * The usage policy is enforced before any truncation: a blocked user
     * loses no history.
     *
     * @return Generator<int, string, mixed, Message>
     *
     * @throws AiException
     */
    public function regenerateReply(User $user, Conversation $conversation, Message $message, AiSource $source = AiSource::Web): Generator
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $this->enforceGenerationRateLimit($user);
        $this->enforceUsagePolicy($user, $conversation, $source);

        $anchor = $this->regenerationAnchor($conversation, $message);

        $this->truncateAfter($anchor);

        $history = $this->context->build($user, $conversation);
        $aiRequest = $this->requests->start($user, $source, $conversation);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::AI_REQUEST_STARTED,
            'success',
            $this->aiMetadata($aiRequest->provider, $aiRequest->model, $conversation),
            $conversation
        );

        $text = '';

        try {
            foreach ($this->ai->stream($history) as $delta) {
                $text .= $delta;

                yield $delta;

                if (connection_aborted()) {
                    break;
                }
            }
        } catch (AiException $e) {
            $this->failRequest($user, $source, $conversation, $aiRequest, $e);

            throw $e;
        }

        if (trim($text) === '') {
            $failure = new AiInvalidResponseException('unknown');
            $this->failRequest($user, $source, $conversation, $aiRequest, $failure);

            throw $failure;
        }

        $aborted = connection_aborted();

        $assistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $text,
            'status' => $aborted ? MessageStatus::Partial : MessageStatus::Complete,
        ]);

        $this->completeRequest($user, $source, $conversation, $aiRequest, $assistant, $aborted);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::MESSAGE_REGENERATED,
            'success',
            ['conversation_id' => (string) $conversation->getKey()],
            $assistant
        );

        return $assistant;
    }

    /**
     * Resolve the user message a regeneration is anchored to: the message
     * itself when it is a user message, otherwise the nearest preceding
     * user message. Membership is verified against the conversation.
     *
     * @throws AiProviderException
     */
    private function regenerationAnchor(Conversation $conversation, Message $message): Message
    {
        $ordered = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $target = $ordered->firstWhere('id', $message->getKey());

        if (! $target instanceof Message) {
            throw new AiProviderException('The message does not belong to this conversation.', 'unknown');
        }

        if ($target->role === MessageRole::User) {
            return $target;
        }

        $anchor = null;

        foreach ($ordered as $item) {
            if ($item->getKey() === $target->getKey()) {
                break;
            }

            if ($item->role === MessageRole::User) {
                $anchor = $item;
            }
        }

        if (! $anchor instanceof Message) {
            throw new AiProviderException('There is no user message to regenerate from.', 'unknown');
        }

        return $anchor;
    }

    /**
     * Delete every message chronologically after the anchor, scoped to its
     * conversation. Keeps linear history consistent after an edit or a
     * regeneration: dependents of replaced content cannot linger.
     */
    private function truncateAfter(Message $anchor): void
    {
        $ordered = Message::query()
            ->where('conversation_id', $anchor->conversation_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        $ids = $ordered->all();
        $position = array_search($anchor->getKey(), $ids, true);

        if ($position === false) {
            return;
        }

        $following = array_slice($ids, $position + 1);

        if ($following !== []) {
            Message::query()
                ->where('conversation_id', $anchor->conversation_id)
                ->whereIn('id', $following)
                ->delete();
        }
    }

    /**
     * Ids a regeneration for the given message will remove: the target
     * assistant message itself plus everything chronologically after the
     * anchor user message. Returned before streaming so the browser can
     * drop the same nodes optimistically; `regenerateReply()` enforces
     * the identical truncation authoritatively.
     *
     * @return array<int, string>
     */
    public function idsRemovedByRegeneration(User $user, Conversation $conversation, Message $message): array
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $ordered = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $target = $ordered->firstWhere('id', $message->getKey());

        if (! $target instanceof Message) {
            return [];
        }

        $anchorId = $target->getKey();

        if ($target->role !== MessageRole::User) {
            $anchorId = null;

            foreach ($ordered as $item) {
                if ($item->getKey() === $target->getKey()) {
                    break;
                }

                if ($item->role === MessageRole::User) {
                    $anchorId = $item->getKey();
                }
            }

            if ($anchorId === null) {
                return [(string) $target->getKey()];
            }
        }

        $ids = [];
        $afterAnchor = false;

        foreach ($ordered as $item) {
            if ($afterAnchor) {
                $ids[] = (string) $item->getKey();
            }

            if ($item->getKey() === $anchorId) {
                $afterAnchor = true;
            }
        }

        return $ids;
    }

    /**
     * Guard the application's AI budget against a single user triggering
     * too many generations. This is separate from upstream provider
     * limits, which the provider layer reports as rate-limit failures.
     *
     * @throws AiRateLimitException
     */
    private function enforceGenerationRateLimit(User $user): void
    {
        $key = "ai-generate:{$user->getKey()}";
        $max = max(1, (int) config('ai.rate_limit.max_attempts', 30));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new AiRateLimitException('app', 429, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Enforce the application usage policy (usage window + cooldown).
     * A blocked attempt is persisted as a `blocked` AI request and
     * audited — the provider is never called — then a clean domain
     * exception carries the remaining cooldown to the caller.
     *
     * @throws AiUsageLimitException
     */
    private function enforceUsagePolicy(User $user, ?Conversation $conversation, AiSource $source): void
    {
        $wasCooling = $this->usage->remainingCooldown($user) !== null;

        try {
            $this->usage->check($user);
        } catch (AiUsageLimitException $e) {
            $this->requests->blocked($user, $source, $conversation);

            $metadata = [
                'retry_after' => $e->retryAfterSeconds(),
                'conversation_id' => $conversation !== null ? (string) $conversation->getKey() : null,
            ];

            $this->audit->record($user, $source, AuditLogService::AI_USAGE_BLOCKED, 'blocked', $metadata, $conversation);

            if (! $wasCooling) {
                $this->audit->record($user, $source, AuditLogService::AI_COOLDOWN_ACTIVATED, 'blocked', $metadata, $conversation);
            }

            throw $e;
        }
    }

    /**
     * Mark an AI request completed and audit it. Operational identifiers
     * only — message content is never written to request or audit rows.
     *
     * A successful exchange is also the memory capture point: durable
     * self-facts from the latest user message persist user-scoped, so
     * later conversations can recall them. Capture is best-effort and
     * never breaks the request.
     */
    private function completeRequest(User $user, AiSource $source, Conversation $conversation, AiRequest $aiRequest, Message $assistant, bool $interrupted): void
    {
        $this->requests->complete($aiRequest, $assistant);

        $this->audit->record(
            $user,
            $source,
            AuditLogService::AI_REQUEST_COMPLETED,
            'success',
            array_merge(
                $this->aiMetadata($aiRequest->provider, $aiRequest->model, $conversation),
                [
                    'assistant_message_id' => (string) $assistant->getKey(),
                    'interrupted' => $interrupted,
                ]
            ),
            $conversation
        );

        try {
            $this->memories->captureFromConversation($user, $conversation);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Mark an AI request failed and audit both the request failure and
     * the provider failure. Sanitized error type/code only — never keys,
     * payloads, prompts, or completions.
     */
    private function failRequest(User $user, AiSource $source, Conversation $conversation, AiRequest $aiRequest, AiException $failure): void
    {
        $this->requests->fail($aiRequest, $failure);

        $metadata = array_merge(
            $this->aiMetadata($aiRequest->provider, $aiRequest->model, $conversation),
            [
                'error_type' => class_basename($failure),
                'error_code' => $failure->status() !== null ? (string) $failure->status() : null,
            ]
        );

        $this->audit->record($user, $source, AuditLogService::AI_REQUEST_FAILED, 'failed', $metadata, $conversation);
        $this->audit->record($user, $source, AuditLogService::PROVIDER_FAILURE, 'failed', $metadata, $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiMetadata(?string $provider, ?string $model, Conversation $conversation): array
    {
        return array_filter([
            'provider' => $provider,
            'model' => $model,
            'conversation_id' => (string) $conversation->getKey(),
        ]);
    }

    /**
     * Update a user-authored message after verifying ownership.
     *
     * Authorization mirrors creation: the acting user must own the parent
     * conversation (the existing ConversationPolicy `view` rule), and only
     * user-role messages are editable — assistant messages are never exposed
     * for editing. Shape validation stays with the caller.
     */
    public function updateUserMessage(User $user, Message $message, string $content, AiSource $source = AiSource::Web): Message
    {
        if ($message->role !== MessageRole::User) {
            throw new AuthorizationException('Only user messages can be edited.');
        }

        Gate::forUser($user)->authorize('view', $message->conversation);

        $message->update(['content' => $content]);

        $fresh = $message->refresh();

        $this->audit->record(
            $user,
            $source,
            AuditLogService::MESSAGE_EDITED,
            'success',
            ['conversation_id' => (string) $fresh->conversation_id],
            $fresh
        );

        return $fresh;
    }
}
