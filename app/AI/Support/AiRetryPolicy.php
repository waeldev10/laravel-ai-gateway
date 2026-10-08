<?php

namespace App\AI\Support;

use App\AI\Exceptions\AiConnectionException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;

/**
 * Centralized retry behavior for AI provider calls.
 *
 * Retries only genuinely transient failures (rate limits, temporary
 * server errors, connection/timeout failures) with bounded exponential
 * backoff and jitter, honoring Retry-After when supplied. Permanent
 * errors are never retried: authentication, authorization, insufficient
 * balance, invalid requests, unknown models, context/token limits,
 * content-policy rejections, invalid responses, and configuration. New
 * permanent exception types stay non-retryable by construction — only
 * the four transient classes listed in `shouldRetry()` are ever
 * retried. Contains no provider-specific business logic: providers
 * translate their errors into normalized exceptions first.
 */
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
