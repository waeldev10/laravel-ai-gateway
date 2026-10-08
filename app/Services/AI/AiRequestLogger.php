<?php

namespace App\Services\AI;

use App\AI\Exceptions\AiException;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class AiRequestLogger
{
    /**
     * Persistent operational record of every AI attempt: who, from which
     * source, through which provider/model, on which conversation, with
     * timing, status, and failure info. Never stores prompts, completions,
     * keys, or headers — operational metadata only, so usage analytics
     * can be built later without sensitive data.
     */
    public function start(User $user, AiSource $source, ?Conversation $conversation = null): AiRequest
    {
        $provider = strtolower(trim((string) config('ai.provider', 'openai')));

        return AiRequest::create([
            'user_id' => $user->getKey(),
            'source' => $source,
            'provider' => $provider,
            'model' => $this->configuredModel($provider),
            'conversation_id' => $conversation?->getKey(),
            'started_at' => now(),
            'status' => AiRequestStatus::Started,
        ]);
    }

    public function complete(AiRequest $request, ?Message $message = null): AiRequest
    {
        $completedAt = now();

        $request->update([
            'message_id' => $message?->getKey(),
            'completed_at' => $completedAt,
            'status' => AiRequestStatus::Completed,
            'duration_ms' => $this->durationMs($request, $completedAt),
        ]);

        return $request->refresh();
    }

    public function fail(AiRequest $request, AiException $failure): AiRequest
    {
        $completedAt = now();

        $request->update([
            'completed_at' => $completedAt,
            'status' => AiRequestStatus::Failed,
            'duration_ms' => $this->durationMs($request, $completedAt),
            'provider_request_id' => $failure->requestId() ?? $request->provider_request_id,
            'error_type' => class_basename($failure),
            'error_code' => $failure->status() !== null ? (string) $failure->status() : null,
        ]);

        return $request->refresh();
    }

    /**
     * Record an attempt refused by the application usage policy without
     * ever calling a provider. No timing, no error type — the audit log
     * owns the cooldown narrative.
     */
    public function blocked(User $user, AiSource $source, ?Conversation $conversation = null): AiRequest
    {
        return AiRequest::create([
            'user_id' => $user->getKey(),
            'source' => $source,
            'provider' => strtolower(trim((string) config('ai.provider', 'openai'))),
            'model' => $this->configuredModel(strtolower(trim((string) config('ai.provider', 'openai')))),
            'conversation_id' => $conversation?->getKey(),
            'started_at' => now(),
            'completed_at' => now(),
            'status' => AiRequestStatus::Blocked,
            'duration_ms' => 0,
        ]);
    }

    private function configuredModel(string $provider): ?string
    {
        $model = config("ai.providers.{$provider}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function durationMs(AiRequest $request, \DateTimeInterface $completedAt): ?int
    {
        if ($request->started_at === null) {
            return null;
        }

        return max(0, (int) $request->started_at->diffInMilliseconds($completedAt));
    }
}
