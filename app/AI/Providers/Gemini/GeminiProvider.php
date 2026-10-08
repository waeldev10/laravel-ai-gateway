<?php

namespace App\AI\Providers\Gemini;

use App\AI\Protocols\GenerateContentProtocol;
use App\AI\Providers\AbstractAiProvider;

class GeminiProvider extends AbstractAiProvider
{
    protected function providerKey(): string
    {
        return 'gemini';
    }

    protected function providerName(): string
    {
        return 'Gemini';
    }

    protected function defaultProtocol(): string
    {
        return 'generate_content';
    }

    protected function supportedProtocols(): array
    {
        return [
            'generate_content' => GenerateContentProtocol::class,
        ];
    }
}
