<?php

namespace App\AI\Providers\DeepSeek;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Protocols\ResponsesProtocol;
use App\AI\Providers\AbstractAiProvider;

class DeepSeekProvider extends AbstractAiProvider
{
    protected function providerKey(): string
    {
        return 'deepseek';
    }

    protected function providerName(): string
    {
        return 'DeepSeek';
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
