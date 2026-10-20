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

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function withDetail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    abstract public function userMessage(): string;
}
