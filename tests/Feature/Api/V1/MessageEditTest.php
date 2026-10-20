<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('api message editing', function () {
    test('a user message can be edited', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $message = $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Original draft',
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(
                route('api.v1.conversations.messages.update', [$conversation, $message]),
                ['content' => 'Revised draft']
            )
            ->assertOk()
            ->assertJsonPath('data.id', (string) $message->getKey())
            ->assertJsonPath('data.content', 'Revised draft');

        expect($message->refresh()->content)->toBe('Revised draft');
    });

    test('an assistant message cannot be edited', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $assistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => 'Assistant text',
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(
                route('api.v1.conversations.messages.update', [$conversation, $assistant]),
                ['content' => 'Hijacked']
            )
            ->assertForbidden();

        expect($assistant->refresh()->content)->toBe('Assistant text');
    });

    test('a message from another conversation resolves as missing', function () {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $second = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $elsewhere = $second->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Elsewhere',
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(
                route('api.v1.conversations.messages.update', [$first, $elsewhere]),
                ['content' => 'Moved']
            )
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        expect($elsewhere->refresh()->content)->toBe('Elsewhere');
    });

    test('another users messages cannot be edited', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = Conversation::factory()->create(['user_id' => $other->getKey()]);
        $message = $theirs->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Theirs',
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(
                route('api.v1.conversations.messages.update', [$theirs, $message]),
                ['content' => 'Hijacked']
            )
            ->assertNotFound();

        expect($message->refresh()->content)->toBe('Theirs');
    });

    test('editing validates the content', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $message = $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Keep me',
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(
                route('api.v1.conversations.messages.update', [$conversation, $message]),
                ['content' => '']
            )
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        expect($message->refresh()->content)->toBe('Keep me');
    });

    test('editing requires the write ability', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $message = $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Keep me',
        ]);
        $token = apiToken($user, [ApiAbilities::MESSAGES_READ]);

        $this->withToken($token)
            ->patchJson(
                route('api.v1.conversations.messages.update', [$conversation, $message]),
                ['content' => 'Changed']
            )
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    });
});
