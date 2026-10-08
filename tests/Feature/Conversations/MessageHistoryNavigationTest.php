<?php

use App\Livewire\Chat\ConversationMessages;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('last navigation visibility', function () {
    test('only the last control renders once the thread reaches the threshold', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->count(6)->create(['conversation_id' => $conversation->id]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-total-count="6"')
            ->and($html)->toContain('data-edge-min="'.ConversationMessages::NAVIGATOR_THRESHOLD.'"')
            ->and($html)->toContain('showEdgeNav')
            ->and($html)->not()->toContain('aria-label="الانتقال إلى أول رسالة"')
            ->and($html)->not()->toContain('أول رسالة')
            ->and($html)->toContain('aria-label="الانتقال إلى آخر رسالة"');
    });

    test('short threads expose their total below the threshold so the control stays hidden', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->count(4)->create(['conversation_id' => $conversation->id]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        // The container is threshold-gated client-side (x-cloak +
        // x-show="showEdgeNav", which stays false for total < 5).
        expect($html)->toContain('data-total-count="4"')
            ->and($html)->toContain('x-show="showEdgeNav"');
    });

    test('button visibility follows the live scroll position', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        // The single last-message button shows only while the user is NOT
        // at the bottom (small threshold, no exact-equality math), plus the
        // loaded-count minimum — never permanently visible.
        expect($source)->not()->toContain('x-show="!atTop"')
            ->and($source)->not()->toContain('x-show="!atBottom"')
            ->and($source)->toContain('x-show="showEdgeNav"')
            ->and($source)->toContain('this.showEdgeNav = total >= min && !this.atBottom')
            ->and($source)->toContain('scrollHeight - scroller.scrollTop - scroller.clientHeight < 48');
    });

    test('no first-message navigation remains', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));
        $client = file_get_contents(resource_path('js/chat-history.js'));

        expect($source)->not()->toContain('أول رسالة')
            ->and($source)->not()->toContain('scrollToEdge')
            ->and($source)->not()->toContain('scrollToLoadedEdge')
            ->and($source)->not()->toContain('chatHistoryEnsureFirst')
            ->and($source)->toContain('scrollToLast')
            ->and($client)->not()->toContain('chatHistoryEnsureFirst')
            ->and($client)->not()->toContain('ensureFirstMessageLoaded')
            ->and($client)->not()->toContain('firstNavRunning');
    });
});

describe('last-message navigation performs no fetch', function () {
    test('scroll loading keeps cursor mechanics with no first-message chain', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        expect($client)->toContain('activeLoad')
            // Same cursor mechanics as scroll loading: before-cursor pages,
            // bounded limit, no OFFSET anywhere in the module.
            ->and($client)->toContain('before=')
            ->and($client)->not()->toContain('offset');
    });

    test('last-message navigation is a DOM scroll only', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        // The last-message action scrolls to the newest loaded node and
        // never touches the history endpoint.
        expect($source)->toContain('scrollToLast')
            ->and($source)->not()->toContain('chatHistoryEnsureFirst')
            ->and($source)->not()->toContain("edge === 'first'");
    });

    test('scroll loading keeps one single-flight', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        // A late arrival shares the in-flight page instead of duplicating it.
        expect($client)->toContain('if (chatHistoryState.loadingOlderMessages) return chatHistoryState.activeLoad');
    });

    test('conversation opens at the bottom after render', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        // Initial open positions the viewport at the latest message
        // synchronously (no timers): the following top check then no-ops
        // at the bottom, so older pages load only on manual scroll-up.
        expect($client)->toContain('scroller.scrollTop = scroller.scrollHeight')
            ->and($client)->toContain('scrollChatToBottom(scroller);')
            ->and($client)->toContain('onChatHistoryScroll();')
            ->and(substr_count($client, 'setTimeout'))->toBe(1);
    });
});

describe('history initializes at the bottom', function () {
    test('the top check runs on initialization under the same guards', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        // Opening a conversation scrolls synchronously to the bottom first;
        // the identical top check then runs once under the same guards
        // (scrollable content, single-flight, hasMore seeding), so it
        // no-ops at the bottom and older pages load only on manual
        // scroll-up with viewport preservation.
        expect($client)->toContain('onChatHistoryScroll();')
            ->and($client)->toContain('if (scroller.scrollHeight <= scroller.clientHeight + 1) return;')
            ->and($client)->toContain("container.dataset.hasMore !== '0'");
    });

    test('client history state resets on re-initialization', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        // After SPA navigation the document no longer contains previously
        // loaded pages: carried-over ids would only grow stale state.
        expect($client)->toContain('chatHistoryState.seenIds = new Set();')
            ->and($client)->toContain('chatHistoryState.activeLoad = null;')
            ->and($client)->not()->toContain('firstNavRunning');
    });

    test('fetch failures surface a recoverable toast instead of failing silently', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        expect($client)->toContain('تعذر تحميل الرسائل السابقة')
            ->and($client)->toContain("CustomEvent('toast'");
    });

    test('no delay hacks in the history load path', function () {
        $client = file_get_contents(resource_path('js/chat-history.js'));

        // The only timer in the module is the pre-existing copy-checkmark
        // revert: no setTimeout polling, no artificial loading states.
        expect(substr_count($client, 'setTimeout'))->toBe(1);
    });
});
