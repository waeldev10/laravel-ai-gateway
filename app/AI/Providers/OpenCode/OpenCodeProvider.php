<?php

namespace App\AI\Providers\OpenCode;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Protocols\ResponsesProtocol;
use App\AI\Providers\AbstractAiProvider;


class OpenCodeProvider extends AbstractAiProvider
{
    protected function providerKey(): string
    {
        return 'opencode';
    }

    protected function providerName(): string
    {
        return 'OpenCode';
    }

    protected function defaultProtocol(): string
    {
        return 'responses';
    }

    protected function supportedProtocols(): array
    {
        return [
            'responses' => ResponsesProtocol::class,
            'chat_completions' => ChatCompletionsProtocol::class,
        ];
    }
}
