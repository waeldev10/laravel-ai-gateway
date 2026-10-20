<?php

namespace App\AI\Support;

use App\AI\Exceptions\AiConnectionException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;


class AiRetryPolicy
{
    public function maxAttempts(): int
    {
        return max(1, (int) config('ai.retry.max_attempts', 3));
    }

    public function shouldRetry(AiException $failure, int $attempt): bool
    {
        if ($attempt + 1 >= $this->maxAttempts()) {
            return false;
        }

        return $failure instanceof AiTimeoutException
            || $failure instanceof AiConnectionException
            || $failure instanceof AiRateLimitException
            || $failure instanceof AiUnavailableException;
    }

    public function sleep(int $attempt, ?int $retryAfterSeconds): void
    {
        $base = max(0, (int) config('ai.retry.base_sleep_ms', 100));
        $cap = max(1, (int) config('ai.retry.max_sleep_ms', 2000));

        $backoff = min($cap, $base * 2 ** max(0, $attempt));

        // Equal jitter: half deterministic backoff, half random spread.
        $sleepMs = (int) ($backoff / 2 + random_int(0, (int) ($backoff / 2)));

        if ($retryAfterSeconds !== null) {
            $sleepMs = max($sleepMs, $retryAfterSeconds * 1000);
        }

        $sleepMs = min($sleepMs, $cap);

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}
