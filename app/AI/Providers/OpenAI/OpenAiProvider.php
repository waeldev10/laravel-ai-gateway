<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Protocols\ResponsesProtocol;
use App\AI\Providers\AbstractAiProvider;

class OpenAiProvider extends AbstractAiProvider
{
    protected function providerKey(): string
    {
        return 'openai';
    }

    protected function providerName(): string
    {
        return 'OpenAI';
    }

    protected function defaultProtocol(): string
    {
        return 'chat_completions';
    }

    protected function supportedProtocols(): array
    {
        return [
            'chat_completions' => ChatCompletionsProtocol::class,
            'responses' => ResponsesProtocol::class,
        ];
    }
}
