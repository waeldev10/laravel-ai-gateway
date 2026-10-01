<?php

use App\Livewire\Chat\MessageComposer;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\ArabicDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('first message livewire navigation', function () {
    test('sending the first message persists exactly once and redirects to the real conversation', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $content = 'ما هو Laravel؟';

        $component = Livewire::test(MessageComposer::class)
            ->set('content', $content)
            ->call('send')
            ->assertHasNoErrors();

        expect(Conversation::count())->toBe(1)
            ->and(Message::count())->toBe(1);

        $conversation = Conversation::first();
        $message = Message::first();

        expect($conversation->user_id)->toBe($user->id)
            ->and($message->content)->toBe($content)
            ->and($message->conversation_id)->toBe($conversation->id)
            ->and($conversation->title)->not()->toBe('')
            ->and($conversation->title)->not()->toBeNull();

        $component->assertRedirect(route('conversations.show', $conversation));

        // The destination loads the actual persisted conversation and message.
        $this->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee($content, false)
            ->assertSee($conversation->title, false);
    });

    test('the transition uses livewire navigation instead of a full reload', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/message-composer.blade.php'));
        $component = file_get_contents(app_path('Livewire/Chat/MessageComposer.php'));

        // New-chat submit navigates SPA-style; links across the flow keep wire:navigate.
        expect($component)->toContain('navigate: true')
            ->and($source)->not()->toContain('window.location');
    });
});

describe('sidebar synchronization after the first message', function () {
    test('the new conversation appears active with its real title and date', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(MessageComposer::class)
            ->set('content', 'ما هو Laravel؟')
            ->call('send')
            ->assertHasNoErrors();

        $conversation = Conversation::first();

        // Fresh sidebar state (what the navigated-to page renders) reflects it.
        $sidebar = Livewire::test(SidebarConversations::class)->html();

        expect($sidebar)->toContain($conversation->title)
            ->and($sidebar)->toContain('الأخيرة')
            ->and($sidebar)->not()->toContain('المثبتة')
            ->and($sidebar)->toContain(ArabicDateTime::timeOnly($conversation->created_at));

        // On the real conversation page the entry is marked as the active one.
        $this->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee($conversation->title, false);
    });
});
