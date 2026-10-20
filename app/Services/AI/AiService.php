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
