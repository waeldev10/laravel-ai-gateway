<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Throwable;

class AiConnectionException extends AiProviderException
{
    public function __construct(string $provider = 'unknown', ?Throwable $previous = null)
    {
        parent::__construct("The {$provider} provider could not be reached.", $provider, null, null, $previous);
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }
}
