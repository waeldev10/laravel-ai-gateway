<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Throwable;

class AiTimeoutException extends AiProviderException
{
    public function __construct(string $provider = 'unknown', ?Throwable $previous = null)
    {
        // The HTTP client surfaces timeouts and connection failures as the
        // same exception type; both are transient, so both map here.
        parent::__construct("The {$provider} provider did not respond in time.", $provider, null, null, $previous);
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }
}
