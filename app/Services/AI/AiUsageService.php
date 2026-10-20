<?php

namespace App\Services\AI;

use App\AI\Exceptions\AiUsageLimitException;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AiUsageService
{
  
    public function check(User $user): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $remaining = $this->remainingCooldown($user);

        if ($remaining !== null) {
            throw new AiUsageLimitException(
                'AI usage limit reached. Cooldown active.',
                $remaining
            );
        }

        if ($this->windowCount($user) >= $this->maxRequests()) {
            $this->activateCooldown($user);

            throw new AiUsageLimitException(
                'AI usage limit reached. Cooldown activated.',
                $this->cooldownMinutes() * 60
            );
        }
    }

    public function remainingCooldown(User $user): ?int
    {
        $until = Cache::get($this->cooldownKey($user));

        if ($until === null) {
            return null;
        }

        $remaining = (int) $until - now()->getTimestamp();

        if ($remaining <= 0) {
            Cache::forget($this->cooldownKey($user));

            return null;
        }

        return $remaining;
    }

    public function windowCount(User $user): int
    {
        return AiRequest::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subMinutes($this->windowMinutes()))
            ->whereIn('status', [
                AiRequestStatus::Started,
                AiRequestStatus::Completed,
                AiRequestStatus::Failed,
            ])
            ->count();
    }

    public function isEnabled(): bool
    {
        return (bool) config('ai.usage.enabled', true);
    }

    public function windowMinutes(): int
    {
        return max(1, (int) config('ai.usage.window_minutes', 10));
    }

    public function maxRequests(): int
    {
        return max(1, (int) config('ai.usage.max_requests', 3));
    }

    public function cooldownMinutes(): int
    {
        return max(1, (int) config('ai.usage.cooldown_minutes', 5));
    }

    private function activateCooldown(User $user): void
    {
        $seconds = $this->cooldownMinutes() * 60;

        Cache::put($this->cooldownKey($user), now()->addSeconds($seconds)->getTimestamp(), $seconds);
    }

    private function cooldownKey(User $user): string
    {
        return "ai-usage-cooldown:{$user->getKey()}";
    }
}
