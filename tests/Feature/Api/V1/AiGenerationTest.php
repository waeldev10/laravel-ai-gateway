<?php

use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeOpenAiAssistant(string $content = 'رد المساعد'): void
{
    config()->set('ai.provider', 'openai');
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.rate_limit.max_attempts', 30);
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);

    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ], 200),
    ]);
}

describe('api ai generation', function () {
    test('sending a message returns the synchronous assistant reply', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        fakeOpenAiAssistant('أهلا بك عبر الواجهة البرمجية');

        $response = $this->withToken(apiToken($user))
            ->postJson(
                route('api.v1.conversations.messages.store', $conversation),
                ['content' => 'مرحبا']
            )
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'role', 'content', 'created_at']])
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'أهلا بك عبر الواجهة البرمجية');

        expect(Message::where('conversation_id', $conversation->getKey())->count())->toBe(2);
    });

    test('invalid message payloads fail with the shared validation shape', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.store', $conversation), ['content' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code', 'errors'])
            ->assertJsonPath('code', 'validation_failed');
    });

    test('messages cannot be sent to another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = Conversation::factory()->create(['user_id' => $other->getKey()]);
        fakeOpenAiAssistant();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.store', $theirs), ['content' => 'hijack'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        expect(Message::where('conversation_id', $theirs->getKey())->count())->toBe(0);
    });

    test('provider failures map to safe errors and keep the user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);

        config()->set('ai.provider', 'openai');
        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.model', 'gpt-4o-mini');
        config()->set('ai.providers.openai.protocol', 'chat_completions');
        config()->set('ai.retry.max_attempts', 1);
        config()->set('ai.retry.base_sleep_ms', 0);
        config()->set('ai.retry.max_sleep_ms', 0);

        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => ['message' => 'server exploded', 'type' => 'server_error']], 500),
        ]);

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.store', $conversation), ['content' => 'kept message'])
            ->assertStatus(503)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code'])
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('status', 503);

        $content = $response->getContent();

        expect($content)->not()->toContain('test-key')
            ->and($content)->not()->toContain('trace')
            ->and(Message::where('conversation_id', $conversation->getKey())->count())->toBe(1)
            ->and(Message::where('conversation_id', $conversation->getKey())->first()->content)->toBe('kept message');
    });

    test('application usage limits stay enforced through the api', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);

        config()->set('ai.usage.enabled', true);
        config()->set('ai.usage.window_minutes', 10);
        config()->set('ai.usage.max_requests', 1);
        config()->set('ai.usage.cooldown_minutes', 5);

        AiRequest::factory()->create([
            'user_id' => $user->getKey(),
            'conversation_id' => $conversation->getKey(),
        ]);

        fakeOpenAiAssistant();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.messages.store', $conversation), ['content' => 'blocked by policy'])
            ->assertStatus(429)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code', 'retry_after'])
            ->assertJsonPath('code', 'usage_limit');

        expect(Message::where('conversation_id', $conversation->getKey())->count())->toBe(1);
    });
});
