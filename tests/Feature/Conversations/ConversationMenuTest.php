<?php

use App\Livewire\Conversation\ConversationMenu;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('conversation page action menu', function () {
    test('the show page exposes rename, pin, share and delete actions', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Menu target']);

        $html = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();

        expect($html)->toContain('خيارات المحادثة')
            ->and($html)->toContain('إعادة تسمية')
            ->and($html)->toContain('تثبيت')
            ->and($html)->toContain('مشاركة')
            ->and($html)->toContain('حذف المحادثة');
    });

    test('pin toggles through the shared service and refreshes the sidebar', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])
            ->call('togglePin')
            ->assertDispatched('conversations-changed')
            ->assertDispatched('toast', type: 'success');

        expect($conversation->refresh()->pinned_at)->not()->toBeNull();

        $html = Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])->html();

        expect($html)->toContain('إلغاء التثبيت');
    });

    test('rename updates the title and notifies header plus sidebar', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old']);
        $this->actingAs($user);

        Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])
            ->call('rename', 'New title')
            ->assertDispatched('conversations-changed')
            ->assertDispatched('conversation-renamed', title: 'New title')
            ->assertDispatched('toast', type: 'success');

        expect($conversation->refresh()->title)->toBe('New title');
    });

    test('rename validates the title without touching the database', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old']);
        $this->actingAs($user);

        Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])
            ->call('rename', '')
            ->assertDispatched('toast', type: 'error');

        expect($conversation->refresh()->title)->toBe('Old');
    });

    test('delete removes the conversation and navigates to a fresh chat', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])
            ->call('delete')
            ->assertRedirect(route('conversations.create'));

        expect(Conversation::find($conversation->id))->toBeNull();
    });

    test('users cannot act on another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        $this->actingAs($user);

        expect(fn () => Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])
        )->toThrow(ModelNotFoundException::class);

        expect(fn () => Livewire::test(ConversationMenu::class, ['conversationId' => (string) $conversation->id])
            ->call('togglePin')
        )->toThrow(ModelNotFoundException::class);

        expect($conversation->refresh()->pinned_at)->toBeNull();
    });
});
