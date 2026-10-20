<?php

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('api conversations', function () {
    test('users only list their own conversations', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $user->conversations()->create(['title' => 'Mine']);
        $other->conversations()->create(['title' => 'Theirs']);

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.index'))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toContain((string) $mine->getKey())
            ->and($ids)->toHaveCount(1);
    });

    test('search filters by title', function () {
        $user = User::factory()->create();
        $user->conversations()->create(['title' => 'Laravel API work']);
        $user->conversations()->create(['title' => 'Groceries']);

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.index', ['search' => 'API']))
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.title'))->toBe('Laravel API work');
    });

    test('a conversation can be created from a title', function () {
        $user = User::factory()->create();

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.store'), ['title' => 'API chat'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'title', 'is_pinned', 'created_at', 'updated_at']]);

        expect(Conversation::where('user_id', $user->getKey())->count())->toBe(1)
            ->and(AuditLog::where('user_id', $user->getKey())
                ->where('action', 'conversation.created')
                ->where('source', 'api')
                ->exists())->toBeTrue();
    });

    test('a conversation can be started from a first message', function () {
        $user = User::factory()->create();

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.store'), ['content' => 'Hello from the API client'])
            ->assertCreated();

        $conversation = Conversation::where('user_id', $user->getKey())->firstOrFail();

        expect($response->json('data.id'))->toBe((string) $conversation->getKey())
            ->and($conversation->messages()->count())->toBe(1)
            ->and($conversation->messages()->first()->content)->toBe('Hello from the API client');
    });

    test('title and content are mutually exclusive', function () {
        $user = User::factory()->create();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.store'), ['title' => 'A', 'content' => 'B'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    });

    test('empty payloads fail with the shared validation shape', function () {
        $user = User::factory()->create();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code', 'errors'])
            ->assertJsonPath('code', 'validation_failed');
    });

    test('another users conversation resolves as not found', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $other->conversations()->create(['title' => 'Theirs']);

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.show', $theirs))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    test('a conversation can be renamed', function () {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Old']);

        $this->withToken(apiToken($user))
            ->patchJson(route('api.v1.conversations.update', $conversation), ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');

        expect($conversation->refresh()->title)->toBe('New');
    });

    test('renaming validates the title', function () {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Old']);

        $this->withToken(apiToken($user))
            ->patchJson(route('api.v1.conversations.update', $conversation), ['title' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    });

    test('another users conversation cannot be renamed or deleted', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $other->conversations()->create(['title' => 'Theirs']);

        $this->withToken(apiToken($user))
            ->patchJson(route('api.v1.conversations.update', $theirs), ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.destroy', $theirs))
            ->assertNotFound();

        expect($theirs->refresh()->title)->toBe('Theirs');
    });

    test('a conversation can be deleted', function () {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Gone']);

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.destroy', $conversation))
            ->assertOk()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data', null);

        expect(Conversation::whereKey($conversation->getKey())->exists())->toBeFalse();
    });

    test('usage status reflects the application policy', function () {
        $user = User::factory()->create();

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.usage-status'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['limited', 'retry_after']])
            ->assertJsonPath('data.limited', false);
    });
});
