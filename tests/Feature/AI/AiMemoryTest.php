<?php

use App\Enums\MessageRole;
use App\Models\AiMemory;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiContextService;
use App\Services\AI\AiMemoryService;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.rate_limit.max_attempts', 1000);
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);
    config()->set('ai.usage.enabled', true);
    config()->set('ai.usage.window_minutes', 10);
    config()->set('ai.usage.max_requests', 1000);
    config()->set('ai.usage.cooldown_minutes', 5);
    config()->set('ai.context.history_limit', 20);
    config()->set('ai.context.memory_limit', 5);
    config()->set('ai.memory.max_per_user', 50);
    config()->set('ai.prompt.default', '');

    config()->set('ai.providers.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.providers.openai.timeout', 30);

    Http::preventStrayRequests();
});

if (! function_exists('fakeMemoryChatSuccess')) {
    function fakeMemoryChatSuccess(string $pattern, string $text = 'رد المساعد'): void
    {
        Http::fake([
            $pattern => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            ], 200),
        ]);
    }
}

describe('cross-conversation memory', function () {
    test('a fact from conversation A is recalled in conversation B', function () {
        $user = User::factory()->create();
        $conversationA = Conversation::factory()->create(['user_id' => $user->id]);
        fakeMemoryChatSuccess('https://api.openai.com/*', 'Nice to meet you, Ahmed!');

        app(MessageService::class)->sendUserMessage($user, $conversationA, 'My name is Ahmed.');

        $memory = AiMemory::query()->where('user_id', $user->getKey())->first();

        expect($memory)->not()->toBeNull()
            ->and($memory->content)->toBe('User\'s name is Ahmed.');

        $conversationB = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversationB->id,
            'role' => MessageRole::User,
            'content' => 'What is my name?',
        ]);

        $built = app(AiContextService::class)->build($user, $conversationB);
        $systemTexts = collect($built)->where('role', 'system')->pluck('content')->all();

        expect(implode("\n", $systemTexts))->toContain('User\'s name is Ahmed.');
    });

    test('the recalled memory reaches the provider payload in the new conversation', function () {
        $user = User::factory()->create();
        $conversationA = Conversation::factory()->create(['user_id' => $user->id]);
        fakeMemoryChatSuccess('https://api.openai.com/*', 'OK');

        app(MessageService::class)->sendUserMessage($user, $conversationA, 'My name is Ahmed.');

        $conversationB = Conversation::factory()->create(['user_id' => $user->id]);

        app(MessageService::class)->sendUserMessage($user, $conversationB, 'What is my name?');

        Http::assertSent(function ($request) {
            $messages = $request['messages'] ?? null;

            if (! is_array($messages)) {
                return false;
            }

            foreach ($messages as $message) {
                if (($message['role'] ?? null) === 'system'
                    && str_contains($message['content'] ?? '', 'User\'s name is Ahmed.')) {
                    return true;
                }
            }

            return false;
        });
    });

    test('memories never leak between users', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversationA = Conversation::factory()->create(['user_id' => $user->id]);
        fakeMemoryChatSuccess('https://api.openai.com/*', 'OK');

        app(MessageService::class)->sendUserMessage($user, $conversationA, 'My name is Ahmed.');

        $conversationB = Conversation::factory()->create(['user_id' => $other->id]);

        app(MessageService::class)->sendUserMessage($other, $conversationB, 'What is my name?');

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return ! str_contains($payload, 'Ahmed');
        });

        expect(AiMemory::query()->where('user_id', $other->getKey())->count())->toBe(0);
    });

    test('conversation history is not shared, only user memory', function () {
        $user = User::factory()->create();
        $conversationA = Conversation::factory()->create(['user_id' => $user->id]);
        fakeMemoryChatSuccess('https://api.openai.com/*', 'OK');

        app(MessageService::class)->sendUserMessage($user, $conversationA, 'My name is Ahmed.');

        $conversationB = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversationB->id,
            'role' => MessageRole::User,
            'content' => 'What is my name?',
        ]);

        $built = app(AiContextService::class)->build($user, $conversationB);
        $contents = array_column($built, 'content');

        expect($contents)->not()->toContain('My name is Ahmed.')
            ->and(implode("\n", $contents))->toContain('User\'s name is Ahmed.');
    });
});

describe('memory storage rules', function () {
    test('re-storing the same fact does not duplicate it', function () {
        $user = User::factory()->create();
        $service = app(AiMemoryService::class);

        $first = $service->remember($user, 'User prefers Arabic.');
        $second = $service->remember($user, '  user PREFERS arabic. ');

        expect($second->getKey())->toBe($first->getKey())
            ->and(AiMemory::query()->where('user_id', $user->getKey())->count())->toBe(1);
    });

    test('a renamed user replaces the previous name instead of contradicting it', function () {
        $user = User::factory()->create();
        $service = app(AiMemoryService::class);

        $service->captureFromMessage($user, 'My name is Ahmed.');
        $service->captureFromMessage($user, 'My name is Ali.');

        $names = AiMemory::query()->where('user_id', $user->getKey())->pluck('content')->all();

        expect($names)->toBe(['User\'s name is Ali.']);
    });

    test('ordinary chatter stores nothing', function () {
        $user = User::factory()->create();
        $service = app(AiMemoryService::class);

        expect($service->captureFromMessage($user, 'How are you today?'))->toBeNull()
            ->and($service->captureFromMessage($user, 'Tell me a joke'))->toBeNull()
            ->and(AiMemory::query()->where('user_id', $user->getKey())->count())->toBe(0);
    });

    test('empty and oversized content is rejected', function () {
        $user = User::factory()->create();

        expect(fn () => app(AiMemoryService::class)->remember($user, '   '))
            ->toThrow(InvalidArgumentException::class);

        expect(fn () => app(AiMemoryService::class)->remember($user, str_repeat('x', 1001)))
            ->toThrow(InvalidArgumentException::class);
    });

    test('the store is capped at the configured maximum', function () {
        config()->set('ai.memory.max_per_user', 3);
        $user = User::factory()->create();
        $service = app(AiMemoryService::class);

        foreach (['Fact one.', 'Fact two.', 'Fact three.', 'Fact four.'] as $fact) {
            $service->remember($user, $fact);
        }

        $contents = AiMemory::query()->where('user_id', $user->getKey())->pluck('content')->all();

        expect($contents)->toHaveCount(3)->not()->toContain('Fact one.');
    });
});

describe('memory relevance and bounds', function () {
    test('the most relevant memories win when the store exceeds the request bound', function () {
        config()->set('ai.context.memory_limit', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $service = app(AiMemoryService::class);

        // Oldest-first would pick gardening; overlap must pick the name.
        $service->remember($user, 'User enjoys gardening on weekends.');
        $service->remember($user, 'User prefers dark mode interfaces.');
        $service->remember($user, 'User\'s name is Ahmed.');
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'What is my name?',
        ]);

        $built = app(AiContextService::class)->build($user, $conversation);
        $systemTexts = implode("\n", collect($built)->where('role', 'system')->pluck('content')->all());

        expect($systemTexts)->toContain('User\'s name is Ahmed.')
            ->and($systemTexts)->not()->toContain('gardening')
            ->and($systemTexts)->not()->toContain('dark mode');
    });

    test('history stays bounded while memories are included', function () {
        config()->set('ai.context.history_limit', 5);
        config()->set('ai.context.memory_limit', 5);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        AiMemory::factory()->create(['user_id' => $user->id, 'content' => 'User\'s name is Ahmed.']);

        for ($i = 0; $i < 10; $i++) {
            Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => "Question {$i} about my name"]);
        }

        $built = app(AiContextService::class)->build($user, $conversation);

        // 1 memory system message + 5 bounded history messages.
        expect($built)->toHaveCount(6)
            ->and($built[0]['role'])->toBe('system')
            ->and($built[1]['content'])->toBe('Question 5 about my name');
    });
});
