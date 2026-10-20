<?php

use App\Models\Conversation;
use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('api pin and bulk delete', function () {
    test('a conversation can be pinned and unpinned', function () {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Pinnable']);

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.pin', $conversation))
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);

        expect($conversation->refresh()->pinned_at)->not()->toBeNull();

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.unpin', $conversation))
            ->assertOk()
            ->assertJsonPath('data.is_pinned', false);

        expect($conversation->refresh()->pinned_at)->toBeNull();
    });

    test('another users conversation cannot be pinned', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $other->conversations()->create(['title' => 'Theirs']);

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.pin', $theirs))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        expect($theirs->refresh()->pinned_at)->toBeNull();
    });

    test('pinning requires the write ability', function () {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Pinnable']);
        $token = apiToken($user, [ApiAbilities::CONVERSATIONS_READ]);

        $this->withToken($token)
            ->postJson(route('api.v1.conversations.pin', $conversation))
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    });

    test('conversations can be deleted in bulk', function () {
        $user = User::factory()->create();
        $keep = $user->conversations()->create(['title' => 'Keep']);
        $first = $user->conversations()->create(['title' => 'First']);
        $second = $user->conversations()->create(['title' => 'Second']);

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.destroyMany'), [
                'ids' => [(string) $first->getKey(), (string) $second->getKey()],
            ])
            ->assertOk()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'Deleted 2 conversations.');

        expect(Conversation::where('user_id', $user->getKey())->count())->toBe(1)
            ->and(Conversation::whereKey($keep->getKey())->exists())->toBeTrue();
    });

    test('bulk delete is all-or-nothing when a foreign id is included', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $user->conversations()->create(['title' => 'Mine']);
        $theirs = $other->conversations()->create(['title' => 'Theirs']);

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.destroyMany'), [
                'ids' => [(string) $mine->getKey(), (string) $theirs->getKey()],
            ])
            ->assertForbidden();

        expect(Conversation::whereKey($mine->getKey())->exists())->toBeTrue()
            ->and(Conversation::whereKey($theirs->getKey())->exists())->toBeTrue();
    });

    test('bulk delete payloads are bounded', function () {
        $user = User::factory()->create();

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.destroyMany'), ['ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $ids = [];

        for ($i = 0; $i < 51; $i++) {
            $ids[] = (string) Str::ulid();
        }

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.conversations.destroyMany'), ['ids' => $ids])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    });
});
