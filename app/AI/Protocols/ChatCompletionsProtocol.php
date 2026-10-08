<?php

namespace App\AI\Protocols;

use App\AI\Contracts\StreamingAiProtocol;
use Illuminate\Http\Client\Response;

/**
 * OpenAI-compatible Chat Completions wire protocol: Bearer authentication,
 * a `{model, messages}` payload on `/chat/completions`, and the assistant
 * text at `choices.0.message.content`.
 *
 * Shared only by providers verified to use exactly this contract.
 *
 * Streaming uses the same endpoint with `stream: true` and Server-Sent
 * Events: `data: {"choices":[{"delta":{"content":"..."}}]}` chunks
 * terminated by `data: [DONE]`.
 */
class ChatCompletionsProtocol implements StreamingAiProtocol
{
    public function endpoint(array $config): string
    {
        return rtrim((string) ($config['base_url'] ?? ''), '/').'/chat/completions';
    }

    public function headers(array $config): array
    {
        return array_merge(
            ['Authorization' => 'Bearer '.(string) ($config['api_key'] ?? '')],
            // Optional ranking headers (e.g. OpenRouter). Only sent when set.
            array_filter([
                'HTTP-Referer' => $config['referer'] ?? null,
                'X-Title' => $config['title'] ?? null,
            ])
        );
    }

    public function payload(array $messages, array $config): array
    {
        return [
            'model' => $config['model'],
            'messages' => array_map(fn (array $message) => [
                'role' => $message['role'],
                'content' => $message['content'],
            ], $messages),
        ];
    }

    public function extractText(Response $response): ?string
    {
        $text = $response->json('choices.0.message.content');

        return is_string($text) ? $text : null;
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
        return self::streamData($line) === '[DONE]';
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

        $delta = $decoded['choices'][0]['delta']['content'] ?? null;

        return is_string($delta) && $delta !== '' ? $delta : null;
    }

    /**
     * Return the payload of an SSE `data:` line, or null when the line is
     * not a data line (blank, comment, or event line).
     */
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
