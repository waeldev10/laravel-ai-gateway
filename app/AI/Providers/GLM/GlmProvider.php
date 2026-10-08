<?php

namespace App\AI\Providers\GLM;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Providers\AbstractAiProvider;

/**
 * Z.AI GLM API (api.z.ai): Bearer authentication with an
 * OpenAI-compatible Chat Completions wire format.
 */
class GlmProvider extends AbstractAiProvider
{
    protected function providerKey(): string
    {
        return 'glm';
    }

    protected function providerName(): string
    {
        return 'GLM';
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
