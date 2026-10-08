<?php

namespace App\AI\Contracts;

/**
 * Streaming capability for wire protocols.
 *
 * Extends the base protocol with the streaming endpoint, the streaming
 * request payload, and incremental Server-Sent Events parsing. All
 * provider/protocol-specific streaming behavior lives here — never in
 * services, controllers, or JavaScript.
 */
interface StreamingAiProtocol extends AiProtocol
{
    /**
     * Fully-qualified streaming request URL.
     *
     * @param  array<string, mixed>  $config
     */
    public function streamEndpoint(array $config): string;

    /**
     * Streaming request payload. Same as the synchronous payload plus the
     * protocol's streaming flag.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function streamPayload(array $messages, array $config): array;

    /**
     * Extract the text delta from one raw SSE line (including the trailing
     * newline). Returns null for heartbeats, comments, control lines, done
     * markers, and malformed lines — callers skip nulls.
     */
    public function extractDelta(string $line): ?string;

    /**
     * Whether the raw SSE line terminates the stream.
     */
    public function isStreamDone(string $line): bool;
}
