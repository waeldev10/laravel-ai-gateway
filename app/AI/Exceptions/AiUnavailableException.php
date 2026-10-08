<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Throwable;

class AiUnavailableException extends AiProviderException
{
    public function __construct(string $provider = 'unknown', ?int $status = null, ?string $requestId = null, ?Throwable $previous = null)
    {
        parent::__construct("The {$provider} provider is currently unavailable.", $provider, $status, $requestId, $previous);
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }
}
