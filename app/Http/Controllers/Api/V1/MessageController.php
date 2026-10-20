<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiSource;
use App\Http\Requests\Api\V1\ListMessagesRequest;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Requests\Api\V1\UpdateMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Services\Conversation\ConversationService;
use App\Services\Message\MessageService;
use App\Support\ApiAbilities;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends ApiController
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    /**
     * Cursor history page: latest page without `before`, older page
     * with it. Same service + bounds as the Web Chat (latest 30,
     * cursor, no OFFSET) — only the serialization differs.
     */
    public function index(ListMessagesRequest $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::MESSAGES_READ)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);

        $validated = $request->validated();
        $limit = (int) ($validated['limit'] ?? MessageService::HISTORY_PAGE_SIZE);
        $before = $validated['before'] ?? null;

        $page = $before !== null && $before !== ''
            ? $this->messages->olderPageFor($request->user(), $item, $before, $limit)
            : $this->messages->latestPageFor($request->user(), $item, $limit);

        return ApiResponse::collection(
            MessageResource::collection($page['messages']),
            [
                'has_more' => $page['hasMore'],
                'oldest_cursor' => $page['oldestCursor'],
            ]
        );
    }

    /**
     * Synchronous AI exchange: persists the user message, generates the
     * assistant reply through AiService, and returns the assistant
     * message. Usage limits and provider failures map via the shared
     * API exception contract.
     */
    public function store(StoreMessageRequest $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::MESSAGES_WRITE)) {
            return $denied;
        }

        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::AI_GENERATE)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);

        $assistant = $this->messages->sendUserMessage(
            $request->user(),
            $item,
            $request->validated('content'),
            AiSource::Api
        );

        return ApiResponse::resource(new MessageResource($assistant), 201);
    }

    /**
     * Inline edit of one user-authored message, mirroring the Web
     * editor: same validation bounds, same service. Assistant messages
     * are never editable; a message outside the conversation resolves
     * as missing.
     */
    public function update(UpdateMessageRequest $request, string $conversation, string $message): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::MESSAGES_WRITE)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);
        $target = $item->messages()->findOrFail($message);

        $updated = $this->messages->updateUserMessage(
            $request->user(),
            $target,
            $request->validated('content'),
            AiSource::Api
        );

        return ApiResponse::resource(new MessageResource($updated));
    }

    /**
     * Synchronous regeneration: same truncation + streaming service as
     * the Web SSE endpoint, consumed to completion without SSE framing.
     * Returns the replacement assistant message plus the ids the
     * regeneration removed, so clients can reconcile optimistically.
     */
    public function regenerate(Request $request, string $conversation, string $message): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::MESSAGES_WRITE)) {
            return $denied;
        }

        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::AI_GENERATE)) {
            return $denied;
        }

        $user = $request->user();
        $item = $this->conversations->findFor($user, $conversation);
        $target = $item->messages()->findOrFail($message);
        $removed = $this->messages->idsRemovedByRegeneration($user, $item, $target);

        $generator = $this->messages->regenerateReply($user, $item, $target, AiSource::Api);

        foreach ($generator as $delta) {
            // Consumed synchronously; deltas are discarded.
        }

        $assistant = $generator->getReturn();

        return ApiResponse::resource(
            new MessageResource($assistant),
            200,
            ['removed_message_ids' => $removed]
        );
    }

    /**
     * Synchronous continuation of the latest interrupted (partial)
     * assistant reply. Fails safely when there is nothing to continue.
     */
    public function continue(Request $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::MESSAGES_WRITE)) {
            return $denied;
        }

        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::AI_GENERATE)) {
            return $denied;
        }

        $user = $request->user();
        $item = $this->conversations->findFor($user, $conversation);

        $generator = $this->messages->continueReply($user, $item, AiSource::Api);

        foreach ($generator as $delta) {
            // Consumed synchronously; deltas are discarded.
        }

        $assistant = $generator->getReturn();

        return ApiResponse::resource(
            new MessageResource($assistant),
            200,
            ['continued' => true]
        );
    }
}
