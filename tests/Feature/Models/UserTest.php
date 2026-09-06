<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user has many conversations', function () {
    $user = User::factory()->create();

    Conversation::factory()->create(['user_id' => $user->id]);
    Conversation::factory()->create(['user_id' => $user->id]);

    expect($user->conversations)->toHaveCount(2);
});

test('deleting a user deletes their conversations', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(Conversation::find($conversation->id))->toBeNull();
});

test('a conversation factory creates its own user when none is given', function () {
    $conversation = Conversation::factory()->create();

    expect($conversation->user)->toBeInstanceOf(User::class);
});
