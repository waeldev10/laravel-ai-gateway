<?php

use App\Livewire\Chat\MessageComposer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('new chat send button empty state', function () {
    test('send button is disabled while the input is empty and enables with content', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/message-composer.blade.php'));
        $script = file_get_contents(resource_path('js/chat-stream.js'));

        // Browser-only UI state: the button starts disabled and plain JS
        // enables it on non-blank input (streaming submit, no Livewire).
        expect($source)->toContain('data-send-btn')
            ->and($source)->toContain('disabled:cursor-not-allowed')
            ->and($source)->toContain('disabled:opacity-40')
            ->and($script)->toContain('trim()');
    });

    test('typing never sends a Livewire request', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/message-composer.blade.php'));

        // The composer submits through fetch to the SSE streaming endpoint:
        // no wire:submit, no $wire call, no entangle, no wire:model — so
        // typing cannot trigger any Livewire request at all.
        expect($source)->not()->toContain('wire:submit')
            ->and($source)->not()->toContain('$wire')
            ->and($source)->not()->toContain('@entangle')
            ->and($source)->not()->toContain('wire:model');
    });

    test('server-side validation still rejects empty submissions', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(MessageComposer::class)
            ->set('content', '')
            ->call('send')
            ->assertHasErrors(['content']);

        Livewire::test(MessageComposer::class)
            ->set('content', '   ')
            ->call('send')
            ->assertHasErrors(['content']);

        expect(Conversation::count())->toBe(0)
            ->and(Message::count())->toBe(0);
    });

    test('valid content still creates the conversation and navigates to it', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class)
            ->set('content', 'ساعدني في كتابة كود PHP للمصادقة')
            ->call('send')
            ->assertHasNoErrors();

        $conversation = Conversation::first();

        expect($conversation)->not()->toBeNull();
        $component->assertRedirect(route('conversations.show', $conversation));
    });

    test('new chat page keeps the real loading state and no fake loading', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/message-composer.blade.php'));

        // The real streaming state is a stop button shown while deltas
        // arrive (AbortController cancels the fetch) — never a Livewire
        // loading hook and never a timer-based fake animation.
        expect($source)->toContain('data-stop-btn')
            ->and($source)->toContain('data-composer-form')
            ->and($source)->not()->toContain('setTimeout');
    });
});
