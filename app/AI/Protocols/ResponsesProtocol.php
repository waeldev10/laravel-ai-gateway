<?php

namespace App\AI\Protocols;

use App\AI\Contracts\StreamingAiProtocol;
use Illuminate\Http\Client\Response;

/**
 * OpenAI Responses API wire protocol: Bearer authentication, a
 * `{model, input}` payload on `/responses`, and the assistant text in the
 * first message item's `output_text` part.
 *
 * Shared only by providers verified to use exactly this contract
 * (e.g. OpenAI itself and OpenCode Zen models served over `/responses`).
 *
 * Streaming uses the same endpoint with `stream: true` and Server-Sent
 * Events: `response.output_text.delta` events carrying a `delta` string,
 * terminated by a `response.completed` event or `data: [DONE]`.
 */
class ResponsesProtocol implements StreamingAiProtocol
{
    public function endpoint(array $config): string
    {
        return rtrim((string) ($config['base_url'] ?? ''), '/').'/responses';
    }

    public function headers(array $config): array
    {
        return ['Authorization' => 'Bearer '.(string) ($config['api_key'] ?? '')];
    }

    public function payload(array $messages, array $config): array
    {
        return [
            'model' => $config['model'],
            'input' => array_map(fn (array $message) => [
                'role' => $message['role'],
                'content' => $message['content'],
            ], $messages),
        ];
    }

    public function extractText(Response $response): ?string
    {
        $output = $response->json('output');

        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'output_text' && isset($part['text']) && is_string($part['text'])) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    public function streamEndpoint(array $config): string
    {
        return $this->endpoint($config);
    }

    public function streamPayload(array $messages, array $config): array
    {
        return array_merge($this->payload($messages, $config), ['stream' => true]);
    }

    public function isStreamDone(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === 'event: response.completed' || $trimmed === 'event: response.done') {
            return true;
        }

        $data = self::streamData($line);

        if ($data === '[DONE]') {
            return true;
        }

        if ($data === null || $data === '') {
            return false;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded)
            && isset($decoded['type'])
            && in_array($decoded['type'], ['response.completed', 'response.done'], true);
    }

    public function extractDelta(string $line): ?string
    {
        $data = self::streamData($line);

        if ($data === null || $data === '' || $data === '[DONE]') {
            return null;
        }

        $decoded = json_decode($data, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Only text deltas; other event types (function calls, metadata)
        // are intentionally ignored.
        if (($decoded['type'] ?? null) !== 'response.output_text.delta') {
            return null;
        }

        $delta = $decoded['delta'] ?? null;

        return is_string($delta) && $delta !== '' ? $delta : null;
    }

    private static function streamData(string $line): ?string
    {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, ':') || str_starts_with($trimmed, 'event:')) {
            return null;
        }

        if (! str_starts_with($trimmed, 'data:')) {
            return null;
        }

        return trim(substr($trimmed, 5));
    }
}
