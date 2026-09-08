<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a conversation has many messages', function () {
    $conversation = Conversation::factory()->create();

    Message::factory()->create(['conversation_id' => $conversation->id]);
    Message::factory()->create(['conversation_id' => $conversation->id]);

    expect($conversation->messages)->toHaveCount(2);
});

test('a conversation belongs to a user', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['user_id' => $user->id]);

    expect($conversation->user)->toBeInstanceOf(User::class)
        ->and($conversation->user->id)->toBe($user->id);
});

test('deleting a conversation deletes its messages', function () {
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create(['conversation_id' => $conversation->id]);

    $conversation->delete();

    expect(Message::find($message->id))->toBeNull();
});

test('a conversation can be created through its factory', function () {
    $conversation = Conversation::factory()->create();

    expect($conversation->title)->not()->toBeNull()
        ->and($conversation->user_id)->not()->toBeNull();
});
