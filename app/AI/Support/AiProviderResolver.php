<?php

namespace App\AI\Support;

use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\DeepSeek\DeepSeekProvider;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Providers\GLM\GlmProvider;
use App\AI\Providers\OpenAI\OpenAiProvider;
use App\AI\Providers\OpenCode\OpenCodeProvider;
use App\AI\Providers\OpenRouter\OpenRouterProvider;

class AiProviderResolver
{
    /**
     * Resolve the configured provider behind the provider contract.
     *
     * The resolver only selects; it performs no HTTP requests, holds no
     * conversation state, and parses no API responses. It is the single
     * place that maps provider names to implementations.
     *
     * @throws AiProviderException
     */
    public function resolve(?string $provider = null): AiProvider
    {
        $name = strtolower(trim($provider ?? (string) config('ai.provider', 'openai')));

        return match ($name) {
            'openai' => app(OpenAiProvider::class),
            'gemini' => app(GeminiProvider::class),
            'openrouter' => app(OpenRouterProvider::class),
            'opencode' => app(OpenCodeProvider::class),
            'glm' => app(GlmProvider::class),
            'deepseek' => app(DeepSeekProvider::class),
            default => throw new AiProviderException("Unsupported AI provider [{$name}].", $name),
        };
    }
}
