<?php

namespace App\AI\Contracts;

use Illuminate\Http\Client\Response;

interface AiProtocol
{

    public function endpoint(array $config): string;

    public function headers(array $config): array;

    public function payload(array $messages, array $config): array;

    public function extractText(Response $response): ?string;
}
