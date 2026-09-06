<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a message belongs to a conversation', function () {
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create(['conversation_id' => $conversation->id]);

    expect($message->conversation)->toBeInstanceOf(Conversation::class)
        ->and($message->conversation->id)->toBe($conversation->id);
});

test('a message role is cast to the MessageRole enum', function () {
    $message = Message::factory()->create();

    expect($message->role)->toBeInstanceOf(MessageRole::class)
        ->and($message->getRawOriginal('role'))->toBeString();
});

test('a message accepts user and assistant roles', function (MessageRole $role) {
    $message = Message::factory()->create(['role' => $role]);

    expect($message->role)->toBe($role);
})->with([
    'user' => [MessageRole::User],
    'assistant' => [MessageRole::Assistant],
]);

test('a message factory creates its own conversation when none is given', function () {
    $message = Message::factory()->create();

    expect($message->conversation)->toBeInstanceOf(Conversation::class);
});
