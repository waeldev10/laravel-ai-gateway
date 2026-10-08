<?php

namespace App\AI\Providers\OpenRouter;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Providers\AbstractAiProvider;

class OpenRouterProvider extends AbstractAiProvider
{
    protected function providerKey(): string
    {
        return 'openrouter';
    }

    protected function providerName(): string
    {
        return 'OpenRouter';
    }

    protected function defaultProtocol(): string
    {
        return 'chat_completions';
    }

    protected function supportedProtocols(): array
    {
        return [
            'chat_completions' => ChatCompletionsProtocol::class,
        ];
    }
}
