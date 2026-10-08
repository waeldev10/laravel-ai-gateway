<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Throwable;

class AiInvalidResponseException extends AiProviderException
{
    public function __construct(string $provider = 'unknown', ?int $status = null, ?string $requestId = null, ?Throwable $previous = null)
    {
        parent::__construct("The {$provider} provider returned an invalid response.", $provider, $status, $requestId, $previous);
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }
}
