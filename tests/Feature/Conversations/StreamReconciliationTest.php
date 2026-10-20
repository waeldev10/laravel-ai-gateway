<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeCompletionStream(): void
{
    config()->set('ai.provider', 'openai');
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.rate_limit.max_attempts', 30);
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);

    $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello']]]])."\n\n";
    $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => ' world']]]])."\n\n";
    $sse .= "data: [DONE]\n\n";

    Http::fake([
        'https://api.openai.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

function streamedEvents($response): array
{
    $events = [];

    foreach (explode("\n\n", $response->streamedContent()) as $frame) {
        $frame = trim($frame);

        if (str_starts_with($frame, 'data:')) {
            $decoded = json_decode(trim(substr($frame, 5)), true);

            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
    }

    return $events;
}

describe('streamed assistant identity', function () {
    test('one generation persists exactly one user message and one assistant message with stable ids', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeCompletionStream();

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'Count my messages']);

        $response->assertOk();

        $events = streamedEvents($response);
        $meta = collect($events)->firstWhere('type', 'meta');
        $done = collect($events)->firstWhere('type', 'done');

        expect($meta['user_message_id'] ?? null)->not()->toBeNull()
            ->and($done['assistant_message_id'] ?? null)->not()->toBeNull()
            ->and($done['assistant_message_id'])->not()->toBe($meta['user_message_id']);

        // Exactly one row per side: the client reconciles these two
        // stable ids, so a second competing representation can never
        // be mistaken for another response.
        $rows = Message::where('conversation_id', $conversation->id)->orderBy('id')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->role)->toBe(MessageRole::User)
            ->and((string) $rows[0]->getKey())->toBe((string) $meta['user_message_id'])
            ->and($rows[1]->role)->toBe(MessageRole::Assistant)
            ->and($rows[1]->content)->toBe('Hello world')
            ->and((string) $rows[1]->getKey())->toBe((string) $done['assistant_message_id']);
    });

    test('the client adopts optimistic nodes into the list instead of clearing them later', function () {
        $client = file_get_contents(resource_path('js/stream-client.js'));

        // Single ownership: the optimistic user/assistant nodes move
        // into [data-message-list-root] before the refresh, so the
        // morph reconciles the same elements in place. At no instant
        // do two nodes represent one message.
        expect($client)->toContain('export function adoptStreamNodes(')
            ->and($client)->toContain('[data-message-list-root]')
            ->and($client)->toContain(':scope > [data-stream-node]');
    });

    test('the composer adopts before refreshing on every completion path', function () {
        $composer = file_get_contents(resource_path('js/chat-stream.js'));

        expect(substr_count($composer, 'adoptStreamNodes()'))->toBeGreaterThanOrEqual(4)
            ->and(substr_count($composer, 'refreshMessageList()'))->toBeGreaterThanOrEqual(4);
    });

    test('message actions adopt the same way', function () {
        $actions = file_get_contents(resource_path('js/message-actions.js'));

        expect(substr_count($actions, 'adoptStreamNodes()'))->toBeGreaterThanOrEqual(3)
            ->and(substr_count($actions, 'refreshMessageList()'))->toBeGreaterThanOrEqual(3);
    });
});
