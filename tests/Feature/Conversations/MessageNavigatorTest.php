<?php

use App\Enums\MessageRole;
use App\Livewire\Chat\ConversationMessages;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('message navigator removed', function () {
    test('threshold constant is kept for the last-message gate', function () {
        expect(ConversationMessages::NAVIGATOR_THRESHOLD)->toBe(5);
    });

    test('no decorative reference lines render even for large conversations', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->count(6)->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->not()->toContain('data-nav-marker')
            ->and($html)->not()->toContain('data-nav-preview')
            ->and($html)->not()->toContain('group/carousel');
    });

    test('no indicator markup, behavior, or styles remain', function () {
        $source = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));

        expect($source)->not()->toContain('data-nav-marker')
            ->and($source)->not()->toContain('data-nav-preview')
            ->and($source)->not()->toContain('group/carousel')
            ->and($source)->not()->toContain('group/mline')
            ->and($source)->not()->toContain('scrollToMsg')
            ->and($source)->not()->toContain('previewTip')
            ->and($source)->not()->toContain('fixed left-2 top-1/2')
            ->and($source)->not()->toContain('التنقل بين الرسائل');
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

describe('last scroll control', function () {
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
            ->and($html)->not()->toContain('أول رسالة')
            ->and($html)->toContain('آخر رسالة')
            ->and($html)->toContain('showEdgeNav')
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

        // Copy, tooltip hover and last-message scrolling are Alpine/DOM only.
        expect(substr_count($source, '$wire.'))->toBe(1)
            ->and($source)->toContain('scrollToLast');
    });
});
