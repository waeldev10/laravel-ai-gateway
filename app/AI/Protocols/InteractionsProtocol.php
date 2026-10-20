<?php

// namespace App\AI\Protocols;

// use App\AI\Contracts\StreamingAiProtocol;
// use App\AI\Exceptions\AiInvalidResponseException;
// use Illuminate\Http\Client\Response;
// use InvalidArgumentException;

// class InteractionsProtocol implements StreamingAiProtocol
// {
//     public function endpoint(array $config): string
//     {
//         return rtrim((string) ($config['base_url'] ?? ''), '/').'/interactions';
//     }

//     public function headers(array $config): array
//     {
//         return ['x-goog-api-key' => (string) ($config['api_key'] ?? '')];
//     }

//     public function payload(array $messages, array $config): array
//     {
//          $input = [];
//         $system = [];

//         foreach ($messages as $message) {
//             $role = $message['role'] ?? '';
//             $content = (string) ($message['content'] ?? '');

//             if ($role === 'system') {
//                 $system[] = $content;
//                 continue;
//             }

//             $type = match ($role) {
//                 'user' => 'user_input',
//                 'assistant', 'model' => 'model_output',
//                 default => throw new InvalidArgumentException(
//                     "Unsupported message role [{$role}]."
//                 ),
//             };

//             $input[] = [
//                 'type' => $type,
//                 'content' => [
//                     ['type' => 'text', 'text' => $content],
//                 ],
//             ];
//         }

//         $payload = [
//             'model' => $config['model'],
//             'input' => $input,
//         ];

//         if ($system !== []) {
//             $payload['system_instruction'] = implode("\n\n", $system);
//         }

//         return $payload;
//     }

//     public function extractText(Response $response): ?string
//     {
//         $steps = $response->json('steps');

//         if (is_array($steps)) {
//             foreach (array_reverse($steps) as $step) {
//                 if (! is_array($step) || ($step['type'] ?? null) !== 'model_output') {
//                     continue;
//                 }

//                 $content = $step['content'] ?? null;

//                 if (! is_array($content)) {
//                     continue;
//                 }

//                 $texts = [];

//                 foreach ($content as $block) {
//                     if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text']) && $block['text'] !== '') {
//                         $texts[] = $block['text'];
//                     }
//                 }

//                 if ($texts !== []) {
//                     return implode('', $texts);
//                 }
//             }

//             return null;
//         }

//         $outputs = $response->json('outputs');

//         if (! is_array($outputs)) {
//             return null;
//         }

//         $texts = [];

//         foreach ($outputs as $output) {
//             if (is_array($output) && ($output['type'] ?? null) === 'text' && isset($output['text']) && is_string($output['text']) && $output['text'] !== '') {
//                 $texts[] = $output['text'];
//             }
//         }

//         return $texts === [] ? null : implode('', $texts);
//     }

//     public function streamEndpoint(array $config): string
//     {
//         return $this->endpoint($config);
//     }

//     public function streamPayload(array $messages, array $config): array
//     {
//         return array_merge($this->payload($messages, $config), ['stream' => true]);
//     }

//     public function isStreamDone(string $line): bool
//     {
//         $trimmed = trim($line);

//         if ($trimmed === 'event: interaction.completed' || $trimmed === 'event: done') {
//             return true;
//         }

//         $data = self::streamData($line);

//         if ($data === '[DONE]') {
//             return true;
//         }

//         if ($data === null || $data === '') {
//             return false;
//         }

//         $decoded = json_decode($data, true);

//         return is_array($decoded) && ($decoded['event_type'] ?? null) === 'interaction.completed';
//     }

//     public function extractDelta(string $line): ?string
//     {
//         $data = self::streamData($line);

//         if ($data === null || $data === '' || $data === '[DONE]') {
//             return null;
//         }

//         $decoded = json_decode($data, true);

//         if (! is_array($decoded)) {
//             return null;
//         }

//         if (($decoded['event_type'] ?? null) === 'error') {
//             throw new AiInvalidResponseException;
//         }

//         if (($decoded['event_type'] ?? null) !== 'step.delta') {
//             return null;
//         }

//         $delta = $decoded['delta'] ?? null;

//         if (! is_array($delta) || ($delta['type'] ?? null) !== 'text') {
//             return null;
//         }

//         $text = $delta['text'] ?? null;

//         return is_string($text) && $text !== '' ? $text : null;
//     }

//     private static function streamData(string $line): ?string
//     {
//         $trimmed = trim($line);

//         if ($trimmed === '' || str_starts_with($trimmed, ':') || str_starts_with($trimmed, 'event:')) {
//             return null;
//         }

//         if (! str_starts_with($trimmed, 'data:')) {
//             return null;
//         }

//         return trim(substr($trimmed, 5));
//     }
// }

namespace App\AI\Protocols;

use App\AI\Contracts\StreamingAiProtocol;
use App\AI\Exceptions\AiInvalidResponseException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

class InteractionsProtocol implements StreamingAiProtocol
{
    public function endpoint(array $config): string
    {
        return rtrim((string) ($config['base_url'] ?? ''), '/').'/interactions';
    }

    public function headers(array $config): array
    {
        return ['x-goog-api-key' => (string) ($config['api_key'] ?? '')];
    }

    public function payload(array $messages, array $config): array
    {
        $input = [];
        $system = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $system[] = $content;
                continue;
            }

            $type = match ($role) {
                'user' => 'user_input',
                'assistant', 'model' => 'model_output',
                default => throw new InvalidArgumentException(
                    "Unsupported message role [{$role}]."
                ),
            };

            $input[] = [
                'type' => $type,
                'content' => [
                    ['type' => 'text', 'text' => $content],
                ],
            ];
        }

        $payload = [
            'model' => $config['model'],
            'input' => $input,
        ];

        if ($system !== []) {
            $payload['system_instruction'] = implode("\n\n", $system);
        }

        return $payload;
    }

    public function extractText(Response $response): ?string
    {
        $steps = $response->json('steps');

        if (! is_array($steps)) {
            return null;
        }

        foreach (array_reverse($steps) as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            $texts = array_column(
                array_filter(
                    $step['content'] ?? [],
                    fn ($block) => is_array($block)
                        && ($block['type'] ?? null) === 'text'
                        && is_string($block['text'] ?? null)
                ),
                'text'
            );

            if ($texts !== []) {
                return implode('', $texts);
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
        return [...$this->payload($messages, $config), 'stream' => true];
    }

    public function isStreamDone(string $line): bool
    {
        $data = self::streamData($line);

        if ($data === '[DONE]') {
            return true;
        }

        $event = json_decode($data ?? '', true);

        return is_array($event)
            && ($event['event_type'] ?? null) === 'interaction.completed';
    }

    public function extractDelta(string $line): ?string
    {
        $event = json_decode(self::streamData($line) ?? '', true);

        if (! is_array($event)) {
            return null;
        }

        if (($event['event_type'] ?? null) === 'error') {
            throw new AiInvalidResponseException('Gemini');
        }

        $delta = $event['delta'] ?? [];

        return ($event['event_type'] ?? null) === 'step.delta'
            && ($delta['type'] ?? null) === 'text'
            && is_string($delta['text'] ?? null)
            && $delta['text'] !== ''
                ? $delta['text']
                : null;
    }

    private static function streamData(string $line): ?string
    {
        $line = trim($line);

        return str_starts_with($line, 'data:')
            ? trim(substr($line, 5))
            : null;
    }
}
