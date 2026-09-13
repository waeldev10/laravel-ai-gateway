<?php

use App\Livewire\ConversationSearch;
use App\Livewire\MessageComposer;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('message composer sends via MessageService with auth', function () {
    $user = User::factory()->create();
    $conv = Conversation::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
    Livewire::test(MessageComposer::class, ['conversation' => $conv])
        ->set('content', 'مرحبا')
        ->call('send')
        ->assertHasNoErrors();
    expect($conv->messages()->count())->toBe(1);
});

test('message composer validates', function () {
    $user = User::factory()->create();
    $conv = Conversation::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
    Livewire::test(MessageComposer::class, ['conversation' => $conv])
        ->set('content', '')
        ->call('send')
        ->assertHasErrors(['content']);
});

test('conversation search live', function () {
    $user = User::factory()->create();
    Conversation::factory()->create(['user_id' => $user->id, 'title' => 'alpha']);
    Conversation::factory()->create(['user_id' => $user->id, 'title' => 'beta']);
    $this->actingAs($user);
    Livewire::test(ConversationSearch::class)
        ->set('search', 'alpha')
        ->call('searchAction')
        ->assertSee('alpha')
        ->assertDontSee('beta')
        ->call('clear')
        ->assertSet('search', '');
});
