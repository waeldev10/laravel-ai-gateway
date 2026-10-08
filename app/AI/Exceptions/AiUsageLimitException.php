<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Throwable;

class AiUsageLimitException extends AiException
{
    public function __construct(
        string $message = 'AI usage limit reached. Please try again later.',
        protected ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 'app-usage', 429, null, $previous);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }
}
