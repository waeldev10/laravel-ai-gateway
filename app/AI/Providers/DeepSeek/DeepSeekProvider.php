<?php

namespace App\AI\Providers\DeepSeek;

use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Protocols\ResponsesProtocol;
use App\AI\Providers\AbstractAiProvider;

/**
 * DeepSeek API (api.deepseek.com): Bearer authentication with an
 * OpenAI-compatible Chat Completions wire format (default), optionally
 * the Responses wire format through configuration alone.
 *
 * The model identifier always comes from configuration (DEEPSEEK_MODEL):
 * model IDs are provider-specific and never hard-coded here.
 */
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
