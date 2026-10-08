<?php

namespace App\AI\Exceptions;

use App\AI\Support\AiErrorPresenter;
use Illuminate\Http\Client\Response;
use Throwable;

class AiRateLimitException extends AiProviderException
{
    public function __construct(string $provider = 'unknown', ?int $status = null, protected ?int $retryAfterSeconds = null, ?string $requestId = null, ?Throwable $previous = null)
    {
        parent::__construct("The {$provider} rate limited the request.", $provider, $status, $requestId, $previous);
    }

    public static function fromResponse(string $provider, Response $response): self
    {
        return new self(
            $provider,
            $response->status(),
            self::parseRetryAfter($response->header('Retry-After')),
            $response->header('x-request-id') ?: null,
        );
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function userMessage(): string
    {
        return AiErrorPresenter::messageFor($this);
    }

    public static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
