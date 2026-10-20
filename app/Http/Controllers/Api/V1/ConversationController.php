<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiSource;
use App\Http\Requests\Api\V1\DestroyManyConversationsRequest;
use App\Http\Requests\Api\V1\IndexConversationRequest;
use App\Http\Requests\Api\V1\StoreConversationRequest;
use App\Http\Requests\Api\V1\UpdateConversationRequest;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Services\Conversation\ConversationService;
use App\Support\ApiAbilities;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends ApiController
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function index(IndexConversationRequest $request): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_READ)) {
            return $denied;
        }

        $items = $this->conversations->listFor($request->user(), $request->validated('search'));

        return ApiResponse::collection(ConversationResource::collection($items));
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_WRITE)) {
            return $denied;
        }

        $validated = $request->validated();

        $conversation = isset($validated['content'])
            ? $this->conversations->startFor($request->user(), $validated['content'], AiSource::Api)
            : $this->conversations->createFor($request->user(), ['title' => $validated['title']], AiSource::Api);

        return ApiResponse::resource(new ConversationResource($conversation), 201);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_READ)) {
            return $denied;
        }

        // Scoped lookup: other users' conversations resolve as missing
        // (404), never as forbidden — no existence oracle.
        $item = $this->conversations->findFor($request->user(), $conversation);

        return ApiResponse::resource(new ConversationResource($item));
    }

    public function update(UpdateConversationRequest $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_WRITE)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);
        $item = $this->conversations->renameFor($request->user(), $item, $request->validated('title'));

        return ApiResponse::resource(new ConversationResource($item));
    }

    public function destroy(Request $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_WRITE)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);
        $this->conversations->deleteFor($request->user(), $item);

        return ApiResponse::action('Conversation deleted.');
    }

    /**
     * Bulk delete, all-or-nothing via the existing service: unknown or
     * foreign ids abort the whole operation and delete nothing.
     */
    public function destroyMany(DestroyManyConversationsRequest $request): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_WRITE)) {
            return $denied;
        }

        $ids = $request->validated('ids', []);
        $this->conversations->deleteMany($request->user(), $ids);

        $count = count($ids);

        return ApiResponse::action("Deleted {$count} ".Str::plural('conversation', $count).'.');
    }

    /**
     * Explicit pin / unpin pair over the shared toggle path:
     * pinning moves the conversation into the pinned group ordered by
     * pinned_at, unpinning returns it to its created_at position.
     */
    public function pin(Request $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_WRITE)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);
        $item = $this->conversations->setPinnedFor($request->user(), $item, true);

        return ApiResponse::resource(new ConversationResource($item));
    }

    public function unpin(Request $request, string $conversation): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::CONVERSATIONS_WRITE)) {
            return $denied;
        }

        $item = $this->conversations->findFor($request->user(), $conversation);
        $item = $this->conversations->setPinnedFor($request->user(), $item, false);

        return ApiResponse::resource(new ConversationResource($item));
    }
}
