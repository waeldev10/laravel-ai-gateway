<?php

namespace App\AI\Protocols;

use App\AI\Contracts\StreamingAiProtocol;
use Illuminate\Http\Client\Response;

/**
 * Gemini generateContent wire protocol: key-by-header authentication, a
 * `{contents}` payload on `/models/{model}:generateContent` with `user`
 * and `model` roles, and the assistant text at
 * `candidates.0.content.parts.0.text`.
 *
 * Streaming uses `:streamGenerateContent?alt=sse` with the same payload;
 * each SSE data line carries a full `candidates` chunk and the stream ends
 * when the body ends.
 */
class GenerateContentProtocol implements StreamingAiProtocol
{
    public function endpoint(array $config): string
    {
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');

        return "{$base}/models/{$config['model']}:generateContent";
    }

    public function headers(array $config): array
    {
        // The key travels in a header so it never appears in URLs that
        // could end up in logs or error reports.
        return ['x-goog-api-key' => (string) ($config['api_key'] ?? '')];
    }

    public function payload(array $messages, array $config): array
    {
        return [
            'contents' => array_map(fn (array $message) => [
                // Gemini uses "model" where the chat domain uses "assistant".
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ], $messages),
        ];
    }

    public function extractText(Response $response): ?string
    {
        $text = $response->json('candidates.0.content.parts.0.text');

        return is_string($text) ? $text : null;
    }

    public function streamEndpoint(array $config): string
    {
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');

        return "{$base}/models/{$config['model']}:streamGenerateContent?alt=sse";
    }

    public function streamPayload(array $messages, array $config): array
    {
        return $this->payload($messages, $config);
    }

    public function isStreamDone(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, ':') || str_starts_with($trimmed, 'event:')) {
            return false;
        }

        if (! str_starts_with($trimmed, 'data:')) {
            return false;
        }

        return trim(substr($trimmed, 5)) === '[DONE]';
    }

    public function extractDelta(string $line): ?string
    {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, ':') || str_starts_with($trimmed, 'event:')) {
            return null;
        }

        if (! str_starts_with($trimmed, 'data:')) {
            return null;
        }

        $data = trim(substr($trimmed, 5));

        if ($data === '' || $data === '[DONE]') {
            return null;
        }

        $decoded = json_decode($data, true);

        if (! is_array($decoded)) {
            return null;
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}
