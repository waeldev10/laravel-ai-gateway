<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

test('user ids are ulids', function () {
    $user = User::factory()->create();
    expect((string) $user->id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
        ->and(Str::isUlid((string) $user->id))->toBeTrue();
});

test('conversation ids are ulids', function () {
    $c = Conversation::factory()->create();
    expect(Str::isUlid((string) $c->id))->toBeTrue();
});

test('message ids are ulids', function () {
    $m = Message::factory()->create();
    expect(Str::isUlid((string) $m->id))->toBeTrue();
});

test('conversation foreign key references user ulid', function () {
    $user = User::factory()->create();
    $c = Conversation::factory()->create(['user_id' => $user->id]);
    expect($c->user_id)->toBe((string) $user->id)
        ->and($c->user->id)->toBe($user->id);
});

test('message foreign key references conversation ulid', function () {
    $c = Conversation::factory()->create();
    $m = Message::factory()->create(['conversation_id' => $c->id]);
    expect($m->conversation_id)->toBe((string) $c->id);
});

test('user conversations relationship works with ulids', function () {
    $user = User::factory()->create();
    Conversation::factory()->count(2)->create(['user_id' => $user->id]);
    expect($user->conversations()->count())->toBe(2);
});

test('conversation messages relationship works with ulids', function () {
    $c = Conversation::factory()->create();
    Message::factory()->count(3)->create(['conversation_id' => $c->id]);
    expect($c->messages()->count())->toBe(3);
});

test('route model binding works with ulids', function () {
    $user = User::factory()->create();
    $c = Conversation::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)->get(route('conversations.show', $c))->assertOk()->assertSee($c->title);
});

test('policy authorizes with ulids and bulk deletion all-or-nothing', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $own = Conversation::factory()->create(['user_id' => $user->id]);
    $foreign = Conversation::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user)->delete(route('conversations.destroyMany'), ['ids' => [$own->id, $foreign->id]])->assertForbidden();
    expect(Conversation::find($own->id))->not->toBeNull()
        ->and(Conversation::find($foreign->id))->not->toBeNull();
});

test('individual deletion and message creation work with ulids', function () {
    $user = User::factory()->create();
    $c = Conversation::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)->post(route('conversations.messages.store', $c), ['content' => 'hello ulid'])->assertRedirect();
    expect($c->messages()->count())->toBe(1);
    $this->actingAs($user)->delete(route('conversations.destroy', $c))->assertRedirect(route('conversations.index'));
    expect(Conversation::find($c->id))->toBeNull();
});
