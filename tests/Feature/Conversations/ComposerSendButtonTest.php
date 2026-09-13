<?php

use App\Livewire\MessageComposer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('new chat send button empty state', function () {
    test('send button is disabled while the input is empty and enables with content', function () {
        $source = file_get_contents(resource_path('views/livewire/message-composer.blade.php'));

        // Browser-only UI state: Alpine disables on empty/whitespace, enables otherwise.
        expect($source)->toContain(':disabled="!text.trim()"')
            ->and($source)->toContain('disabled:cursor-not-allowed')
            ->and($source)->toContain('disabled:opacity-40')
            // Keyboard submit is guarded the same way.
            ->and($source)->toContain('if(text.trim())');
    });

    test('typing never sends a Livewire request', function () {
        $source = file_get_contents(resource_path('views/livewire/message-composer.blade.php'));

        // Plain entanglement is deferred by default: typing syncs locally,
        // only submit talks to the server. Neither `.live` (a request per
        // keystroke) nor `.defer` (not a valid entangle modifier: it
        // evaluates to `undefined` and silently destroys the two-way sync,
        // leaving Livewire validating a stale empty `content`) may be used.
        expect($source)->toContain("@entangle('content')")
            ->and($source)->not()->toContain("@entangle('content').live")
            ->and($source)->not()->toContain("@entangle('content').defer")
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
        $source = file_get_contents(resource_path('views/livewire/message-composer.blade.php'));

        expect($source)->toContain('wire:loading.attr="disabled"')
            ->and($source)->toContain('wire:loading')
            ->and($source)->not()->toContain('setTimeout');
    });
});
