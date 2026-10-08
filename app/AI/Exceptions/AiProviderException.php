<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Throwable;

class AiProviderException extends AiException
{
    public function __construct(string $message, string $provider = 'unknown', ?int $status = null, ?string $requestId = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $provider, $status, $requestId, $previous);
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }
}
