<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\AiException;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    /**
     * Return one cursor page of an owned conversation's history as JSON.
     *
     * Thin HTTP layer for the Web Chat's incremental history loading: the
     * browser keeps already-loaded messages and asks for the next older
     * page when the user scrolls up. Without `before` this is the latest
     * page (same as the initial paint); with `before` it is the page
     * older than that cursor message id. Authorization and paging live in
     * MessageService; this method only validates input and serializes.
     *
     * This is session-authenticated Web Chat UI support under routes/web.php,
     * not the token-authenticated /api/v1 REST API (Plan 4).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'before' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.MessageService::HISTORY_PAGE_SIZE],
        ]);

        $limit = (int) ($validated['limit'] ?? MessageService::HISTORY_PAGE_SIZE);
        $before = $validated['before'] ?? null;

        $page = $before !== null && $before !== ''
            ? $this->messages->olderPageFor($request->user(), $conversation, $before, $limit)
            : $this->messages->latestPageFor($request->user(), $conversation, $limit);

        return response()->json([
            'data' => $page['messages']->map(fn (Message $message) => [
                'id' => (string) $message->getKey(),
                'role' => $message->role instanceof MessageRole ? $message->role->value : (string) $message->role,
                'content' => $message->content,
                'status' => $message->status instanceof MessageStatus ? $message->status->value : (string) ($message->status ?? ''),
                'created_at' => $message->created_at?->toIso8601String(),
                // Server-rendered with the same partial as the initial
                // paint, so history-loaded messages look identical
                // (markdown, timestamps, actions) with no client renderer.
                'html' => view('livewire.chat.message-item', ['m' => $message])->render(),
            ])->all(),
            'has_more' => $page['hasMore'],
            'oldest_cursor' => $page['oldestCursor'],
        ]);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        // The user message is persisted before the AI provider is called, so
        // a provider failure still keeps it: redirect back with a safe error
        // instead of losing the message.
        try {
            $this->messages->sendUserMessage(
                $request->user(),
                $conversation,
                $request->validated('content'),
                AiSource::Web
            );
        } catch (AiException $e) {
            report($e);

            return redirect()
                ->route('conversations.show', $conversation)
                ->with('toast', ['type' => 'error', 'title' => 'تعذر الحصول على رد المساعد', 'message' => $e->userMessage()]);
        }

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('status', 'تم إرسال رسالتك بنجاح.');
    }
}
