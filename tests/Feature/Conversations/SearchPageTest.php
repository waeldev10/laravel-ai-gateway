<?php

use App\Livewire\ConversationSearch;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('dedicated search page', function () {
    test('authenticated users can open the search page', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('conversations.search'))
            ->assertOk()
            ->assertSee('بحث في المحادثات', false);
    });

    test('guests cannot open the search page', function () {
        $this->get(route('conversations.search'))->assertRedirect(route('login'));
    });

    test('empty search shows the latest 5 conversations', function () {
        $user = User::factory()->create();
        foreach (range(1, 7) as $i) {
            Conversation::factory()->create([
                'user_id' => $user->id,
                'title' => "Conversation {$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->assertSee('Conversation 7')
            ->assertSee('Conversation 3')
            ->assertDontSee('Conversation 2')
            ->assertDontSee('Conversation 1');
    });

    test('load more appends the next batch of 5', function () {
        $user = User::factory()->create();
        foreach (range(1, 7) as $i) {
            Conversation::factory()->create([
                'user_id' => $user->id,
                'title' => "Conversation {$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->assertDontSee('Conversation 1')
            ->call('loadMore')
            ->assertSee('Conversation 1');
    });

    test('search returns only matching authorized results', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Work project']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Travel plan']);
        Conversation::factory()->create(['user_id' => $other->id, 'title' => 'Work secret']);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->set('search', 'Work')
            ->assertSee('Work project')
            ->assertDontSee('Travel plan')
            ->assertDontSee('Work secret');
    });
});

describe('search page bulk selection', function () {
    test('selection is fully client-side until submit', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'First']);

        $this->actingAs($user);
        $html = Livewire::test(ConversationSearch::class)->html();

        // Alpine owns selection: no Livewire round trip for check/select-all/sync.
        expect($html)->toContain('x-model="selected"')
            ->and($html)->toContain(':checked="allChecked"')
            ->and($html)->toContain('x-text="selected.length"')
            ->and($html)->not()->toContain('wire:model.live="selected"')
            ->and($html)->not()->toContain('toggleSelectAll');
    });

    test('bulk delete removes the submitted conversations', function () {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'First']);
        $second = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Second']);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('deleteConversations', [(string) $first->id, (string) $second->id])
            ->assertDispatched('close-confirm-modal', id: 'page-delete')
            ->assertDispatched('conversations-changed')
            ->assertDispatched('toast', type: 'success');

        expect(Conversation::count())->toBe(0);
    });

    test('bulk delete cannot remove another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Conversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('deleteConversations', [(string) $foreign->id])
            ->assertForbidden();

        expect(Conversation::find($foreign->id))->not()->toBeNull();
    });

    test('bulk delete with no selection shows an error toast', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('deleteConversations', [])
            ->assertDispatched('toast', type: 'error');
    });
});

describe('search page single actions', function () {
    test('single delete removes the conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('deleteConversations', [(string) $conversation->id]);

        expect(Conversation::find($conversation->id))->toBeNull();
    });

    test('rename updates the title on submit only', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old']);

        $this->actingAs($user);

        // Opening/typing the modal is client-side: no Livewire state involved.
        $html = Livewire::test(ConversationSearch::class)->html();
        expect($html)->toContain('x-model="title"')
            ->and($html)->toContain('renameConversation(conversationId, title)');

        Livewire::test(ConversationSearch::class)
            ->call('renameConversation', (string) $conversation->id, 'New')
            ->assertDispatched('close-rename-modal', id: 'search-rename')
            ->assertDispatched('conversations-changed')
            ->assertDispatched('toast', type: 'success');

        expect($conversation->refresh()->title)->toBe('New');
    });

    test('rename validates the title on submit', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Old']);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('renameConversation', (string) $conversation->id, '')
            ->assertDispatched('toast', type: 'error');

        expect($conversation->refresh()->title)->toBe('Old');
    });

    test('rename of another users conversation fails', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user);

        expect(fn () => Livewire::test(ConversationSearch::class)
            ->call('renameConversation', (string) $conversation->id, 'New')
        )->toThrow(ModelNotFoundException::class);
    });

    test('pin toggles through the service', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        Livewire::test(ConversationSearch::class)
            ->call('togglePin', (string) $conversation->id)
            ->assertDispatched('toast', type: 'success');

        expect($conversation->refresh()->pinned_at)->not()->toBeNull();
    });
});
