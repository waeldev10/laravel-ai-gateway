<?php

namespace App\AI\Providers\OpenCode;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Protocols\ResponsesProtocol;
use App\AI\Providers\AbstractAiProvider;

/**
 * OpenCode Zen (opencode.ai/zen): the hosted inference gateway, not the
 * local opencode server API. Zen serves different models over different
 * protocols, so the protocol is explicit configuration — never inferred
 * from the model name. Nothing OpenCode-specific leaks outside this
 * provider and its selected protocol.
 */
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
