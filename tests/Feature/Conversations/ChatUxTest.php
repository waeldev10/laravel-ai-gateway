<?php

use App\Enums\MessageRole;
use App\Livewire\Chat\ConversationMessages;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('typing indicator', function () {
    test('assistant bubbles start with animated dots and never a block cursor', function () {
        $client = file_get_contents(resource_path('js/stream-client.js'));

        expect($client)->toContain('data-typing-dots')
            ->and($client)->toContain('typing-dot')
            ->and($client)->toContain('setStreamingText')
            ->and($client)->not()->toContain('data-stream-cursor')
            ->and($client)->not()->toContain('▌');
    });

    test('no javascript file renders a block cursor anymore', function () {
        foreach (['stream-client.js', 'chat-stream.js', 'message-actions.js'] as $file) {
            $source = file_get_contents(resource_path("js/{$file}"));

            expect($source)->not()->toContain('▌', "{$file} must not render a block cursor")
                ->and($source)->not()->toContain('data-stream-cursor');
        }
    });

    test('dots are removed as soon as the first real chunk arrives', function () {
        $client = file_get_contents(resource_path('js/stream-client.js'));

        expect($client)->toContain("querySelector('[data-typing-dots]')")
            ->and($client)->toContain('dots.remove()');
    });

    test('dots animate via css and respect reduced motion', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('.typing-dots')
            ->and($css)->toContain('.typing-dot')
            ->and($css)->toContain('@keyframes typing-blink')
            ->and($css)->toContain('prefers-reduced-motion');
    });
});

describe('message role decoration', function () {
    test('only user bubbles carry the vertical accent, never assistant bubbles', function () {
        // Per-message markup lives in the shared message-item partial.
        $source = file_get_contents(resource_path('views/livewire/Chat/message-item.blade.php'));
        $parent = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));
        $client = file_get_contents(resource_path('js/stream-client.js'));

        // Exactly one accent hook in the template: the user branch. The
        // assistant branch stays decoration-free, and the single CSS rule
        // binds the bar to the inline-start side (RTL-correct) in the
        // primary token color — never by child order or position.
        expect(substr_count($source, 'chat-user-accent'))->toBe(1)
            ->and($parent)->not()->toContain('chat-user-accent')
            ->and($css)->toContain('.chat-user-accent')
            ->and($css)->toContain('border-inline-start')
            ->and($css)->toContain('var(--color-primary)')
            // The optimistic client user bubble reuses the same hook;
            // the client assistant bubble never does.
            ->and($client)->toContain('chat-user-accent')
            ->and(substr_count($client, 'chat-user-accent'))->toBe(1);
    });

    test('rendered thread shows the accent once for a user message and none for the assistant', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'سؤال المستخدم',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رد المساعد',
        ]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect(substr_count($html, 'chat-user-accent'))->toBe(1);
    });
});

describe('edge jump labels', function () {
    test('last-message control is scroll-driven and hides at the bottom', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        expect($source)->toContain('data-total-count')
            ->and($source)->toContain('data-edge-min')
            ->and($source)->toContain('showEdgeNav')
            // The exact Alpine scroll hooks stay intact for edge tracking.
            ->and($source)->toContain('initChatScroll')
            // Visibility follows the live scroll position (with a small
            // threshold), gated by the loaded-count minimum — never a
            // permanently visible button.
            ->and($source)->toContain('this.showEdgeNav = total >= min && !this.atBottom')
            ->and($source)->toContain('scrollHeight - scroller.scrollTop - scroller.clientHeight < 48')
            ->and($source)->toContain('x-show="showEdgeNav"')
            ->and($source)->toContain('scrollToLast')
            ->and($source)->not()->toContain('أول رسالة')
            ->and($source)->toContain('آخر رسالة');
    });

    test('short threads expose their total below the edge threshold', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::Assistant]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-total-count="3"')
            ->and($html)->toContain('data-edge-min="'.ConversationMessages::NAVIGATOR_THRESHOLD.'"');
    });
});

describe('new chat first send transition', function () {
    test('create page is a chat layout with hero hooks and a single composer', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('conversations.create'))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-create-root')
            ->and($html)->toContain('data-create-hero')
            ->and($html)->toContain('data-chat-scroll')
            ->and($html)->toContain('data-chat-stream-slot')
            ->and($html)->toContain('data-create-composer-slot')
            ->and($html)->toContain('min-h-[50vh]')
            ->and(substr_count($html, '<textarea'))->toBe(1);
    });

    test('only the first submit switches the centered empty state to bottom composer', function () {
        $script = file_get_contents(resource_path('js/chat-stream.js'));
        $create = file_get_contents(resource_path('views/conversations/create.blade.php'));

        // The layout switch lives in one function with exactly one call
        // site: the validated submit handler for the first message.
        // Typing (input listener), textarea growth, Alpine/Livewire init
        // have no path to it.
        expect($script)->toContain('data-create-hero')
            ->and($script)->toContain('chatStarted')
            ->and($script)->toContain('if (isNew) activateCreateChat();')
            ->and(substr_count($script, 'activateCreateChat()'))->toBe(2)
            // Layout state is referenced exactly once (inside the switch):
            // nothing else restyles the composer or its parents.
            ->and(substr_count($script, 'data-create-composer-slot'))->toBe(1)
            ->and(substr_count($script, 'chatStarted'))->toBe(1)
            // No node relocation, no Livewire request for the transition.
            ->and($script)->not()->toContain('cloneNode')
            ->and($script)->not()->toContain('$wire');

        // Blade: centered empty-state flow (hero, then stream slot, then
        // the in-flow composer) with the layout hook the switch toggles.
        // The composer wrapper itself is never relocated by script.
        $heroPos = strpos($create, 'data-create-hero');
        $streamPos = strpos($create, 'data-chat-stream-slot');
        $slotPos = strpos($create, 'data-create-composer-slot');
        expect($heroPos)->not()->toBeFalse()
            ->and($streamPos)->toBeGreaterThan($heroPos)
            ->and($slotPos)->toBeGreaterThan($streamPos)
            ->and($create)->toContain('data-create-content')
            ->and($create)->toContain('data-chat-scroll');
    });
});
