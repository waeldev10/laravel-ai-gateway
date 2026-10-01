<?php

use App\Livewire\Chat\ConversationMessages;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('message navigator', function () {
    test('threshold is defined once and the navigator hides for tiny conversations', function () {
        expect(ConversationMessages::NAVIGATOR_THRESHOLD)->toBe(5);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->count(4)->create(['conversation_id' => $conversation->id]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->not()->toContain('data-nav-marker');
    });

    test('navigator marks every message of larger conversations with real targets', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $messages = Message::factory()->count(6)->create(['conversation_id' => $conversation->id]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect(substr_count($html, 'data-nav-marker'))->toBe(6);

        foreach ($messages as $message) {
            expect($html)->toContain('data-target-id="'.$message->id.'"')
                ->and($html)->toContain('data-message-id="'.$message->id.'"');
        }
    });

    test('navigator sits at the viewport edge with tiny horizontal lines', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        // Fixed to the viewport (outside the message column entirely) and
        // vertically centered; markers are 2px horizontal strokes that
        // borrow the primary token on hover/focus. Previews live in-flow
        // beside their own line, so the lines never shift.
        expect($source)->toContain('fixed left-2 top-1/2')
            ->and($source)->toContain('h-[2px] w-4')
            ->and($source)->toContain('group-hover/mline:bg-[var(--color-primary)]')
            ->and($source)->toContain('justify-end gap-1.5')
            ->and($source)->not()->toContain('absolute inset-y-0')
            ->and($source)->not()->toContain('absolute start-full');
    });

    test('carousel is transparent idle and paints one panel on hover only', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        // Idle: transparent border, no background — only the lines show.
        // Hover/focus: the same box paints its panel (paint-only change,
        // so lines cannot shift).
        expect($source)->toContain('border border-transparent')
            ->and($source)->toContain('hover:bg-white/80')
            ->and($source)->toContain('dark:hover:bg-zinc-900/80')
            ->and($source)->toContain('focus-within:bg-white/80')
            ->and($source)->toContain('role="group" aria-label="التنقل بين الرسائل"');
    });

    test('tooltips belong to the row previews, never to the marker lines', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        preg_match_all('/<button\b(?:\{\{.*?\}\}|"[^"]*"|\'[^\']*\'|[^>])*>/s', $source, $matches);

        $markers = array_values(array_filter($matches[0], fn ($tag) => str_contains($tag, 'data-nav-marker')));
        $previews = array_values(array_filter($matches[0], fn ($tag) => str_contains($tag, 'data-nav-preview')));

        expect($markers)->not()->toBeEmpty()
            ->and($previews)->toHaveCount(count($markers));

        foreach ($markers as $tag) {
            expect($tag)->not()->toContain('showTip')
                ->and($tag)->not()->toContain('previewTip');
        }

        foreach ($previews as $tag) {
            expect($tag)->toContain('previewTip($el)');
        }
    });
    test('preview panel is capped, self-scrolling, and backed by real messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $long = 'مقدمة طويلة للرسالة '.str_repeat('نص إضافي ', 30);
        Message::factory()->create(['conversation_id' => $conversation->id, 'content' => $long]);
        Message::factory()->count(5)->create(['conversation_id' => $conversation->id]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        $preview = Str::limit($long, 100, '…');

        // One shared container holds a preview beside every line; previews
        // reveal together with the lines on container hover.
        expect($html)->toContain('group/carousel')
            ->and($html)->toContain('group-hover/carousel:block')
            ->and($html)->toContain('overflow-y-auto')
            ->and($html)->toContain('overscroll-contain')
            ->and($html)->toContain('data-nav-preview')
            // Preview items carry short real text and jump to their message.
            ->and($html)->toContain($preview)
            ->and(mb_strlen($preview))->toBeLessThan(mb_strlen($long))
            // The actual message body is never truncated.
            ->and($html)->toContain($long);
    });

    test('message hover uses the shared custom tooltip, never a native title', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'content' => 'رسالة للتحويم']);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('role="tooltip"')
            ->and($html)->toContain('data-preview="رسالة للتحويم"')
            ->and($html)->not()->toContain(' title="');
    });
});

describe('first and last scroll controls', function () {
    test('controls render above the composer with scroll wiring and no livewire', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'content' => 'الأولى']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'content' => 'الأخيرة']);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-chat-scroll')
            ->and($html)->toContain('أول رسالة')
            ->and($html)->toContain('آخر رسالة')
            ->and($html)->toContain('x-show="!atTop"')
            ->and($html)->toContain('x-show="!atBottom"')
            ->and($html)->toContain('initChatScroll');
    });

    test('controls stay hidden without messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->not()->toContain('أول رسالة')
            ->and($html)->not()->toContain('آخر رسالة')
            ->and($html)->not()->toContain('data-nav-marker');
    });

    test('scroll and hover paths involve no livewire calls', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        // Copy, tooltip hover, marker jumps and edge scrolling are Alpine/DOM only.
        expect(substr_count($source, '$wire.'))->toBe(1)
            ->and($source)->toContain('scrollTo');
    });
});
