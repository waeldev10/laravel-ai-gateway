<?php

use App\Livewire\ConversationSearch;
use App\Livewire\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('toast from controllers', function () {
    test('renaming flashes a success toast', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old']);

        $this->actingAs($user)
            ->from(route('conversations.index'))
            ->patch(route('conversations.update', $conversation), ['title' => 'Toasted'])
            ->assertRedirect()
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'success');

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('تمت إعادة التسمية', false);
    });

    test('bulk delete flashes a success toast', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [(string) $conversation->id]])
            ->assertRedirect()
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'success');
    });

    test('each toast type renders from session flash', function () {
        foreach (['success', 'error', 'warning', 'info'] as $type) {
            $this->withSession(['toast' => ['type' => $type, 'title' => "Title {$type}", 'message' => "Message {$type}"]])
                ->get(route('login'))
                ->assertOk()
                ->assertSee("Title {$type}", false)
                ->assertSee("Message {$type}", false);
        }
    });

    test('legacy status flash renders once as info', function () {
        $this->withSession(['status' => 'تم إرسال رسالتك بنجاح.'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('تم إرسال رسالتك بنجاح.', false);
    });
});

describe('toast from livewire', function () {
    test('pin, rename and delete dispatch to the same toast event', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Toasty']);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('togglePin', (string) $conversation->id)
            ->assertDispatched('toast', type: 'success');

        Livewire::test(SidebarConversations::class)
            ->call('togglePin', (string) $conversation->id)
            ->assertDispatched('toast', type: 'success');

        Livewire::test(ConversationSearch::class)
            ->call('renameConversation', (string) $conversation->id, 'Renamed')
            ->assertDispatched('toast', type: 'success');

        Livewire::test(ConversationSearch::class)
            ->call('deleteConversations', [(string) $conversation->id])
            ->assertDispatched('toast', type: 'success');
    });
});

describe('toast placement', function () {
    test('exactly one toast renderer exists in both layouts', function () {
        $authHtml = $this->get(route('login'))->assertOk()->getContent();

        $user = User::factory()->create();
        $appHtml = $this->actingAs($user)->get(route('conversations.search'))->assertOk()->getContent();

        foreach ([$appHtml, $authHtml] as $html) {
            expect(substr_count($html, 'aria-label="التنبيهات"'))->toBe(1)
                ->and($html)->toContain('@toast.window')
                ->and($html)->toContain('z-[80]');
        }
    });

    test('toast is fixed top-center without physical side assumptions', function () {
        $html = $this->get(route('login'))->getContent();

        expect($html)->toContain('fixed inset-x-0 top-3')
            ->and($html)->toContain('max-w-sm');
    });
});
