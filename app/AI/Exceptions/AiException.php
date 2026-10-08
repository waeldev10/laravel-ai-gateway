<?php

namespace App\AI\Exceptions;

use RuntimeException;
use Throwable;

abstract class AiException extends RuntimeException
{
    protected ?string $detail = null;

    public function __construct(
        string $message = '',
        protected string $provider = 'unknown',
        protected ?int $status = null,
        protected ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Sanitized provider diagnostic (e.g. "[invalid_api_key] Incorrect API
     * key provided"). Built only from known error shapes and truncated —
     * safe for logs, never shown to end users as-is.
     */
    public function detail(): ?string
    {
        return $this->detail;
    }

    public function withDetail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    /**
     * Safe, user-facing message. Never contains keys, headers, payloads,
     * raw provider errors, or stack traces.
     */
    abstract public function userMessage(): string;
}
