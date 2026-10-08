<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProtocol;
use App\AI\Contracts\AiProvider;
use App\AI\Contracts\StreamingAiProtocol;
use App\AI\Exceptions\AiAuthenticationException;
use App\AI\Exceptions\AiAuthorizationException;
use App\AI\Exceptions\AiConnectionException;
use App\AI\Exceptions\AiContentPolicyException;
use App\AI\Exceptions\AiContextLimitException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiInsufficientBalanceException;
use App\AI\Exceptions\AiInvalidRequestException;
use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiModelNotFoundException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;
use App\AI\Support\AiRetryPolicy;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared provider orchestration: configuration, timeouts, centralized
 * retries for transient failures, failure normalization, and safe logging.
 *
 * This class knows no concrete protocol: each provider declares the
 * protocols it supports as configuration data, and all wire behavior
 * (endpoint, authentication, payload, extraction) lives in the selected
 * AiProtocol. Adding a protocol never requires editing this class.
 */
abstract class AbstractAiProvider implements AiProvider
{
    /**
     * Config key under `ai.providers.*`.
     */
    abstract protected function providerKey(): string;

    /**
     * Display name used in safe logs and technical messages. Never a secret.
     */
    abstract protected function providerName(): string;

    /**
     * Protocol used when configuration does not name one explicitly.
     */
    abstract protected function defaultProtocol(): string;

    /**
     * Protocols this provider supports, as configuration data mapping
     * protocol names to implementations. Guards against misconfiguration.
     *
     * @return array<string, class-string<AiProtocol>>
     */
    abstract protected function supportedProtocols(): array;

    public function __construct(private readonly AiRetryPolicy $retry) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    final public function generate(array $messages): string
    {
        $config = $this->validatedConfig();
        $protocol = $this->protocol($config);
        $timeout = max(1, (int) ($config['timeout'] ?? 30));

        $attempt = 0;

        while (true) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($protocol->headers($config))
                    ->acceptJson()
                    ->post($protocol->endpoint($config), $protocol->payload($messages, $config));
            } catch (ConnectionException $e) {
                $failure = $this->connectionFailure($e);

                if (! $this->retry->shouldRetry($failure, $attempt)) {
                    throw $failure;
                }

                $this->retry->sleep($attempt, null);
                $attempt++;

                continue;
            }

            if ($response->successful()) {
                return $this->validatedText($response, $protocol);
            }

            $failure = $this->normalizedFailure($response);

            if (! $this->retry->shouldRetry($failure, $attempt)) {
                throw $failure;
            }

            $this->retry->sleep($attempt, $failure instanceof AiRateLimitException ? $failure->retryAfterSeconds() : null);
            $attempt++;
        }
    }

    /**
     * Stream the assistant's reply as incremental text deltas.
     *
     * The upstream response is consumed incrementally from the PSR stream —
     * never buffered via `body()` — and each protocol-owned delta is
     * yielded immediately so callers can forward it to the browser.
     *
     * Retries apply only before the first upstream byte, reusing the same
     * transient-failure policy as `generate()`. Once the stream has started
     * (HTTP 200 accepted), failures surface immediately without retrying.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return Generator<int, string, mixed, void>
     */
    final public function stream(array $messages): Generator
    {
        $config = $this->validatedConfig();
        $protocol = $this->protocol($config);

        if (! $protocol instanceof StreamingAiProtocol) {
            throw new AiProviderException(
                "The {$this->providerName()} provider does not support streaming.",
                $this->providerKey()
            );
        }

        $timeout = max(1, (int) ($config['timeout'] ?? 30));
        $attempt = 0;

        while (true) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($protocol->headers($config))
                    ->acceptJson()
                    ->withOptions(['stream' => true])
                    ->post($protocol->streamEndpoint($config), $protocol->streamPayload($messages, $config));
            } catch (ConnectionException $e) {
                $failure = $this->connectionFailure($e);

                if (! $this->retry->shouldRetry($failure, $attempt)) {
                    throw $failure;
                }

                $this->retry->sleep($attempt, null);
                $attempt++;

                continue;
            }

            if (! $response->successful()) {
                $failure = $this->normalizedFailure($response);

                if (! $this->retry->shouldRetry($failure, $attempt)) {
                    throw $failure;
                }

                $this->retry->sleep($attempt, $failure instanceof AiRateLimitException ? $failure->retryAfterSeconds() : null);
                $attempt++;

                continue;
            }

            yield from $this->yieldStreamDeltas($response, $protocol);

            return;
        }
    }

    /**
     * Read the upstream PSR body incrementally and yield protocol-owned
     * text deltas as they arrive. Malformed single lines are skipped; an
     * empty stream (no usable text at all) fails as an invalid response.
     *
     * @return Generator<int, string, mixed, void>
     *
     * @throws AiInvalidResponseException
     */
    private function yieldStreamDeltas(Response $response, StreamingAiProtocol $protocol): Generator
    {
        $body = $response->toPsrResponse()->getBody();

        if ($body->isSeekable()) {
            try {
                $body->rewind();
            } catch (\Throwable) {
                // Non-rewindable fakes fall through and read from the start.
            }
        }

        $buffer = '';
        $yielded = 0;

        while (! $body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                if ($body->eof()) {
                    break;
                }

                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos + 1);
                $buffer = substr($buffer, $pos + 1);

                if ($protocol->isStreamDone($line)) {
                    return;
                }

                $delta = $protocol->extractDelta($line);

                if ($delta !== null && $delta !== '') {
                    $yielded++;

                    yield $delta;
                }
            }
        }

        if (trim($buffer) !== '') {
            if ($protocol->isStreamDone($buffer)) {
                return;
            }

            $delta = $protocol->extractDelta($buffer);

            if ($delta !== null && $delta !== '') {
                $yielded++;

                yield $delta;
            }
        }

        if ($yielded === 0) {
            $this->log('AI provider returned an empty stream.', $response);

            throw new AiInvalidResponseException(
                $this->providerName(),
                $response->status(),
                $this->requestId($response),
            );
        }
    }

    /**
     * Select the protocol from configuration, falling back to the
     * provider default. Protocol selection is explicit data — never
     * inferred from model names in PHP. The supported set comes from
     * the concrete provider, so new protocols never touch this class.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws AiProviderException
     */
    protected function protocol(array $config): AiProtocol
    {
        $name = strtolower(trim((string) ($config['protocol'] ?? $this->defaultProtocol())));
        $supported = $this->supportedProtocols();

        if (! isset($supported[$name])) {
            throw new AiProviderException(
                "The {$this->providerName()} provider does not support the [{$name}] protocol.",
                $this->providerKey()
            );
        }

        $protocol = app($supported[$name]);

        if (! $protocol instanceof AiProtocol) {
            throw new AiProviderException(
                "The {$this->providerName()} provider does not support the [{$name}] protocol.",
                $this->providerKey()
            );
        }

        return $protocol;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    protected function validatedConfig(): array
    {
        $config = config("ai.providers.{$this->providerKey()}", []);

        if (! is_array($config) || ! isset($config['api_key']) || ! is_string($config['api_key']) || $config['api_key'] === '') {
            throw new AiProviderException("The {$this->providerName()} provider is not configured. Missing API key.", $this->providerKey());
        }

        if (! isset($config['model']) || ! is_string($config['model']) || $config['model'] === '') {
            throw new AiProviderException("The {$this->providerName()} provider is not configured. Missing model.", $this->providerKey());
        }

        return $config;
    }

    /**
     * Distinguish timeouts from other connection failures using the
     * transported cURL diagnostics. Both are transient; the split keeps
     * error reporting honest.
     */
    private function connectionFailure(ConnectionException $e): AiException
    {
        $message = strtolower($e->getMessage());
        $previous = $e->getPrevious();

        if ($previous !== null) {
            $message .= ' '.strtolower($previous->getMessage());
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return new AiTimeoutException($this->providerName(), $e);
        }

        return new AiConnectionException($this->providerName(), $e);
    }

    /**
     * @throws AiInvalidResponseException
     */
    private function validatedText(Response $response, AiProtocol $protocol): string
    {
        $text = $protocol->extractText($response);

        if (! is_string($text) || trim($text) === '') {
            $this->log('AI provider returned an invalid response.', $response);

            $failure = new AiInvalidResponseException(
                $this->providerName(),
                $response->status(),
                $this->requestId($response),
            );

            throw $this->withDiagnosticDetail($failure, $response);
        }

        return $text;
    }

    /**
     * Normalize an upstream failure into a specific technical exception.
     *
     * Classification uses the HTTP status first, then provider signals
     * (error code, error type, sanitized message) — status alone cannot
     * distinguish e.g. a 400 invalid request from a 400 model-not-found,
     * or a 429 slow-down from a 429 empty balance. Raw provider text is
     * only ever used for these internal signals, never shown to users.
     */
    private function normalizedFailure(Response $response): AiException
    {
        $status = $response->status();
        $requestId = $this->requestId($response);

        $this->log('AI provider request failed.', $response);

        $error = $this->sanitizedError($response);
        $signals = strtolower(trim(implode(' ', array_filter([
            $error['code'] ?? null,
            $error['type'] ?? null,
            $error['message'] ?? null,
        ]))));

        $failure = match (true) {
            $status === 401 => new AiAuthenticationException($this->providerName(), $status, $requestId),
            $status === 402 => new AiInsufficientBalanceException($this->providerName(), $status, $requestId),
            $status === 403 => new AiAuthorizationException($this->providerName(), $status, $requestId),
            $status === 404 => new AiModelNotFoundException($this->providerName(), $status, $requestId),
            $status === 408 => new AiTimeoutException($this->providerName()),
            $status === 429 && self::signalsInsufficientBalance($signals) => new AiInsufficientBalanceException($this->providerName(), $status, $requestId),
            $status === 429 => AiRateLimitException::fromResponse($this->providerName(), $response),
            $status === 413 => new AiContextLimitException($this->providerName(), $status, $requestId),
            $status >= 500 => new AiUnavailableException($this->providerName(), $status, $requestId),
            self::signalsInsufficientBalance($signals) => new AiInsufficientBalanceException($this->providerName(), $status, $requestId),
            self::signalsContextLimit($signals) => new AiContextLimitException($this->providerName(), $status, $requestId),
            self::signalsContentPolicy($signals) => new AiContentPolicyException($this->providerName(), $status, $requestId),
            self::signalsModelNotFound($signals) => new AiModelNotFoundException($this->providerName(), $status, $requestId),
            $status === 400 || $status === 422 => new AiInvalidRequestException($this->providerName(), $status, $requestId),
            default => new AiProviderException(
                "The {$this->providerName()} provider request failed.",
                $this->providerKey(),
                $status,
                $requestId,
            ),
        };

        return $this->withDiagnosticDetail($failure, $response);
    }

    /**
     * Match normalized signals against plain substrings, or `/.../`
     * entries treated as case-insensitive regular expressions.
     *
     * @param  array<int, string>  $patterns
     */
    private static function matchesAny(string $signals, array $patterns): bool
    {
        if ($signals === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '/')) {
                if (preg_match($pattern.'i', $signals) === 1) {
                    return true;
                }

                continue;
            }

            if (str_contains($signals, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function signalsInsufficientBalance(string $signals): bool
    {
        return self::matchesAny($signals, [
            'insufficient_balance',
            'insufficient_quota',
            'insufficient_funds',
            'insufficient balance',
            'insufficient quota',
            'insufficient funds',
            'quota exceeded',
            'out of credits',
            'out of quota',
            'top up',
            'top-up',
            'add credits',
        ]);
    }

    private static function signalsContextLimit(string $signals): bool
    {
        return self::matchesAny($signals, [
            'context_length_exceeded',
            'context_length',
            'max_tokens',
            'too_many_tokens',
            'input_too_long',
            'input_too_large',
            'token_limit',
            'tokens_exceeded',
            'context_too_large',
            'context limit',
            'context length',
            'context window',
            'maximum context',
            'too many tokens',
            'token limit',
            'input too long',
            'prompt too long',
            'request too large',
            '/exceed.{0,30}(token|context|maximum)/',
            '/token.{0,30}(exceed|limit)/',
        ]);
    }

    private static function signalsContentPolicy(string $signals): bool
    {
        return self::matchesAny($signals, [
            'content_policy_violation',
            'policy_violation',
            'content_filter',
            'safety_violation',
            'moderation',
            'content policy',
            'policy violation',
            'safety policy',
            'blocked by',
            'violates',
            'inappropriate',
            'harmful content',
            '/refus/',
        ]);
    }

    private static function signalsModelNotFound(string $signals): bool
    {
        return self::matchesAny($signals, [
            'model_not_found',
            'model_not_exist',
            'unknown model',
            'no such model',
            '/model[^.]{0,60}(does not exist|not found|not exist|unavailable|unknown)/',
            '/does not exist[^.]{0,60}model/',
        ]);
    }

    private function withDiagnosticDetail(AiException $failure, Response $response): AiException
    {
        $error = $this->sanitizedError($response);

        $detail = $error['message'];

        if ($detail !== null && $error['code'] !== null) {
            $detail = "[{$error['code']}] {$detail}";
        } elseif ($detail === null) {
            $detail = $error['code'];
        }

        return $failure->withDetail($detail);
    }

    /**
     * Extract a sanitized provider diagnostic from known error shapes
     * only (OpenAI-style `error.message`/`error.code`/`error.type`,
     * Gemini-style `error.status`, or a top-level `message`). Values are
     * truncated plain strings — never raw bodies, headers, or
     * credentials. The `type` feeds internal classification only.
     *
     * @return array{code: ?string, type: ?string, message: ?string}
     */
    private function sanitizedError(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            return ['code' => null, 'type' => null, 'message' => null];
        }

        $error = $data['error'] ?? null;
        $message = null;
        $code = null;
        $type = null;

        if (is_string($error)) {
            $message = $error;
        } elseif (is_array($error)) {
            if (isset($error['message']) && is_string($error['message'])) {
                $message = $error['message'];
            }

            if (isset($error['type']) && is_string($error['type']) && $error['type'] !== '') {
                $type = $error['type'];
            }

            foreach (['code', 'type', 'status'] as $key) {
                $value = $error[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    $code = $value;

                    break;
                }

                if (is_int($value)) {
                    $code = (string) $value;

                    break;
                }
            }
        } elseif (isset($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
        }

        return [
            'code' => self::cleanDiagnostic($code),
            'type' => self::cleanDiagnostic($type),
            'message' => self::cleanDiagnostic($message),
        ];
    }

    private static function cleanDiagnostic(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if ($value === '') {
            return null;
        }

        // Redact key-like material a provider may echo back (e.g. an
        // invalid key fragment): diagnostics stay useful, secrets never
        // reach logs or user-facing detail.
        $value = (string) preg_replace(
            ['/\bsk-[A-Za-z0-9\-_]{8,}\b/', '/\bbearer\s+[A-Za-z0-9\-._~+\/=]{8,}/i'],
            '[redacted]',
            $value
        );

        return mb_substr($value, 0, 200);
    }

    private function requestId(Response $response): ?string
    {
        $requestId = $response->header('x-request-id');

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    private function log(string $message, Response $response): void
    {
        // Sanitized diagnostics only: provider, status, request id, and
        // the provider's own error code/message. Never keys, headers,
        // payloads, prompts, or completions.
        $error = $this->sanitizedError($response);

        Log::warning($message, array_filter([
            'provider' => $this->providerName(),
            'status' => $response->status(),
            'request_id' => $this->requestId($response),
            'error_code' => $error['code'],
            'error' => $error['message'],
        ]));
    }
}
