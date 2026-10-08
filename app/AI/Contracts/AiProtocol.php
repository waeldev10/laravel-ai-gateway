<?php

namespace App\AI\Contracts;

use Illuminate\Http\Client\Response;

/**
 * A generation protocol: how a provider exposes its API on the wire.
 *
 * A protocol owns endpoint construction, authentication headers, request
 * transformation, and response extraction. It always works from the
 * normalized message shape and provider-agnostic configuration — never
 * from secrets owned elsewhere or hard-coded provider assumptions.
 *
 * @see AiProvider for the application-facing capability.
 */
interface AiProtocol
{
    /**
     * Fully-qualified request URL.
     *
     * @param  array<string, mixed>  $config
     */
    public function endpoint(array $config): string;

    /**
     * Request headers, including credentials. Never logged.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function headers(array $config): array;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function payload(array $messages, array $config): array;

    /**
     * Extract the normalized assistant text. Return null when the
     * response shape is not usable.
     */
    public function extractText(Response $response): ?string;
}
