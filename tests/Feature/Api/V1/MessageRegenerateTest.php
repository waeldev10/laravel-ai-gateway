<?php

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeOpenAiStream(string $first = 'Hello', string $second = ' world'): void
{
    config()->set('ai.provider', 'openai');
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.rate_limit.max_attempts', 30);
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);

    $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => $first]]]])."\n\n";
    $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $second]]]])."\n\n";
    $sse .= "data: [DONE]\n\n";

    Http::fake([
        'https://api.openai.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

describe('api regenerate and continue', function () {
    test('an assistant reply can be regenerated synchronously', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $question = $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
        ]);
        $stale = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => 'Stale answer',
        ]);
        fakeOpenAiStream('Fresh', ' answer');

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.regenerate', [$conversation, $question]))
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'role', 'content'], 'meta' => ['removed_message_ids']])
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Fresh answer');

        expect(Message::whereKey($stale->getKey())->exists())->toBeFalse()
            ->and($response->json('meta.removed_message_ids'))->toContain((string) $stale->getKey())
            ->and(Message::where('conversation_id', $conversation->getKey())->count())->toBe(2);
    });

    test('regeneration in another users conversation resolves as missing', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = Conversation::factory()->create(['user_id' => $other->getKey()]);
        $question = $theirs->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Theirs',
        ]);
        fakeOpenAiStream();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.regenerate', [$theirs, $question]))
            ->assertNotFound();

        expect(Message::where('conversation_id', $theirs->getKey())->count())->toBe(1);
    });

    test('regeneration respects the application usage limit', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $question = $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Question',
        ]);

        config()->set('ai.usage.enabled', true);
        config()->set('ai.usage.window_minutes', 10);
        config()->set('ai.usage.max_requests', 1);
        config()->set('ai.usage.cooldown_minutes', 5);

        AiRequest::factory()->create([
            'user_id' => $user->getKey(),
            'conversation_id' => $conversation->getKey(),
        ]);

        fakeOpenAiStream();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.regenerate', [$conversation, $question]))
            ->assertStatus(429)
            ->assertJsonPath('code', 'usage_limit');

        expect(Message::where('conversation_id', $conversation->getKey())->count())->toBe(1);
    });

    test('an interrupted reply can be continued synchronously', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Question',
        ]);
        $partial = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => 'Partial',
            'status' => MessageStatus::Partial,
        ]);
        fakeOpenAiStream(' continued', '');

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.continue', $conversation))
            ->assertOk()
            ->assertJsonPath('data.id', (string) $partial->getKey())
            ->assertJsonPath('meta.continued', true);

        $fresh = $partial->refresh();

        expect($fresh->content)->toBe('Partial continued')
            ->and($fresh->status)->toBe(MessageStatus::Complete);
    });

    test('continuing without an interrupted reply fails safely', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Question',
        ]);
        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => 'Complete answer',
            'status' => MessageStatus::Complete,
        ]);
        fakeOpenAiStream();

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.continue', $conversation))
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_failed');

        expect($response->getContent())->not()->toContain('trace');
    });
});
