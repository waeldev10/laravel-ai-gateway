<?php

namespace App\AI\Contracts;

interface StreamingAiProtocol extends AiProtocol
{
    
    public function streamEndpoint(array $config): string;

    public function streamPayload(array $messages, array $config): array;

    public function extractDelta(string $line): ?string;

    public function isStreamDone(string $line): bool;
}
