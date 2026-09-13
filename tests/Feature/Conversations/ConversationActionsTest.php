<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('conversation rename', function () {
    test('users can rename their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old title']);

        $this->actingAs($user)
            ->from(route('conversations.index'))
            ->patch(route('conversations.update', $conversation), ['title' => 'New title'])
            ->assertRedirect();

        expect($conversation->refresh()->title)->toBe('New title');
    });

    test('rename validates the title', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old title']);

        $this->actingAs($user)
            ->from(route('conversations.index'))
            ->patch(route('conversations.update', $conversation), ['title' => ''])
            ->assertSessionHasErrors('title');

        expect($conversation->refresh()->title)->toBe('Old title');
    });

    test('users cannot rename another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id, 'title' => 'Old title']);

        $this->actingAs($user)
            ->patch(route('conversations.update', $conversation), ['title' => 'New title'])
            ->assertForbidden();

        expect($conversation->refresh()->title)->toBe('Old title');
    });

    test('guests cannot rename conversations', function () {
        $conversation = Conversation::factory()->create();

        $this->patch(route('conversations.update', $conversation), ['title' => 'New title'])
            ->assertRedirect(route('login'));
    });
});

describe('conversation pin', function () {
    test('users can pin and unpin their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        expect($conversation->pinned_at)->toBeNull();

        $this->actingAs($user)
            ->from(route('conversations.index'))
            ->patch(route('conversations.pin', $conversation))
            ->assertRedirect();

        expect($conversation->refresh()->pinned_at)->not()->toBeNull();

        $this->actingAs($user)
            ->from(route('conversations.index'))
            ->patch(route('conversations.pin', $conversation))
            ->assertRedirect();

        expect($conversation->refresh()->pinned_at)->toBeNull();
    });

    test('pinned conversations come first', function () {
        $user = User::factory()->create();
        $old = Conversation::factory()->create([
            'user_id' => $user->id, 'title' => 'Pinned old', 'created_at' => now()->subDay(),
        ]);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Fresh']);

        $this->actingAs($user)->patch(route('conversations.pin', $old));

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSeeInOrder(['Pinned old', 'Fresh']);
    });

    test('users cannot pin another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->patch(route('conversations.pin', $conversation))
            ->assertForbidden();

        expect($conversation->refresh()->pinned_at)->toBeNull();
    });

    test('guests cannot pin conversations', function () {
        $conversation = Conversation::factory()->create();

        $this->patch(route('conversations.pin', $conversation))
            ->assertRedirect(route('login'));
    });
});
