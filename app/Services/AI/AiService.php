<?php

namespace App\Services\AI;

use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Support\AiProviderResolver;
use Generator;
use Throwable;

class AiService
{
    public function __construct(private readonly AiProviderResolver $resolver) {}

    /**
     * Generate the assistant's reply text for normalized chat messages
     * through the configured provider.
     *
     * This service is the application-facing AI interface: it resolves the
     * configured provider, delegates generation to it, and normalizes
     * unexpected failures. It knows nothing about provider-specific
     * payloads, HTTP, or response shapes — and nothing about entry
     * points: every channel shares this service.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws AiException
     */
    public function generate(array $messages): string
    {
        try {
            return $this->resolver->resolve()->generate($messages);
        } catch (AiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new AiProviderException('The AI provider failed unexpectedly.', 'unknown', null, null, $e);
        }
    }

    /**
     * Stream the assistant's reply as incremental text deltas through the
     * configured provider. Same boundary as `generate()`: resolution and
     * failure normalization only, no provider-specific knowledge.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return Generator<int, string, mixed, void>
     *
     * @throws AiException
     */
    public function stream(array $messages): Generator
    {
        try {
            yield from $this->resolver->resolve()->stream($messages);
        } catch (AiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new AiProviderException('The AI provider failed unexpectedly.', 'unknown', null, null, $e);
        }
    }
}
