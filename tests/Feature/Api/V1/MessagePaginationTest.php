<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedApiHistory(Conversation $conversation, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        Message::factory()->create([
            'conversation_id' => $conversation->getKey(),
            'role' => $i % 2 === 0 ? MessageRole::Assistant : MessageRole::User,
            'content' => "api history message {$i}",
        ]);
    }
}

describe('api message history', function () {
    test('the latest page holds at most 30 messages with pagination state', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        seedApiHistory($conversation, 35);

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', $conversation))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'role', 'content', 'status', 'created_at']],
                'meta' => ['has_more', 'oldest_cursor'],
            ]);

        expect($response->json('data'))->toHaveCount(30)
            ->and($response->json('meta.has_more'))->toBeTrue()
            ->and($response->json('meta.oldest_cursor'))->toBe($response->json('data.0.id'));
    });

    test('older pages load by cursor without overlap', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        seedApiHistory($conversation, 35);

        $first = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', $conversation))
            ->assertOk();

        $second = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', [
                $conversation, 'before' => $first->json('meta.oldest_cursor'),
            ]))
            ->assertOk();

        $firstIds = collect($first->json('data'))->pluck('id')->all();

        expect($second->json('data'))->toHaveCount(5)
            ->and($second->json('meta.has_more'))->toBeFalse();

        foreach ($second->json('data') as $item) {
            expect($item['id'])->not()->toBeIn($firstIds);
        }
    });

    test('an empty conversation returns an empty page', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', $conversation))
            ->assertOk();

        expect($response->json('data'))->toBeEmpty()
            ->and($response->json('meta.has_more'))->toBeFalse()
            ->and($response->json('meta.oldest_cursor'))->toBeNull();
    });

    test('history responses never include server-rendered html', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        seedApiHistory($conversation, 3);

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', $conversation))
            ->assertOk();

        foreach ($response->json('data') as $item) {
            expect($item)->not()->toHaveKey('html');
        }
    });

    test('page size is bounded', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        seedApiHistory($conversation, 40);

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', [$conversation, 'limit' => 31]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', [$conversation, 'limit' => 10]))
            ->assertOk()
            ->assertJsonCount(10, 'data');
    });

    test('history of another users conversation resolves as not found', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = Conversation::factory()->create(['user_id' => $other->getKey()]);
        seedApiHistory($theirs, 3);

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', $theirs))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    test('guests receive the shared unauthorized shape', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);

        $this->getJson(route('api.v1.conversations.messages.index', $conversation))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});
