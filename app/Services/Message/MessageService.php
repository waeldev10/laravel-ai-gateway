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

    public const HISTORY_PAGE_SIZE = 30;

    public function listFor(User $user, Conversation $conversation): Collection
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function latestPageFor(User $user, Conversation $conversation, int $limit = self::HISTORY_PAGE_SIZE): array
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $this->historyPage($conversation, null, $limit);
    }

    public function olderPageFor(User $user, Conversation $conversation, ?string $beforeId, int $limit = self::HISTORY_PAGE_SIZE): array
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $this->historyPage($conversation, $beforeId, $limit);
    }

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

    public function sendUserMessage(User $user, Conversation $conversation, string $content, AiSource $source = AiSource::Web): Message
    {
        $this->createUserMessage($user, $conversation, $content, $source);

        return $this->generateReply($user, $conversation, $source);
    }

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

    
    private function enforceGenerationRateLimit(User $user): void
    {
        $key = "ai-generate:{$user->getKey()}";
        $max = max(1, (int) config('ai.rate_limit.max_attempts', 30));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new AiRateLimitException('app', 429, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }


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

    private function aiMetadata(?string $provider, ?string $model, Conversation $conversation): array
    {
        return array_filter([
            'provider' => $provider,
            'model' => $model,
            'conversation_id' => (string) $conversation->getKey(),
        ]);
    }

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
