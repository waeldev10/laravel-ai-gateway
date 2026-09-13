<?php

use App\Enums\MessageRole;
use App\Livewire\ConversationMessages;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('message edit and copy buttons', function () {
    test('user messages expose edit and copy while assistant messages expose copy only', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'رسالة قابلة للتعديل',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رد غير قابل للتعديل',
        ]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect(substr_count($html, 'aria-label="تعديل الرسالة"'))->toBe(1)
            ->and(substr_count($html, 'aria-label="نسخ الرسالة"'))->toBe(2)
            ->and($html)->toContain('تعديل')
            ->and($html)->toContain('إلغاء')
            ->and($html)->toContain('نسخ');
    });

    test('copy is fully client-side with per-message checkmark feedback', function () {
        $source = file_get_contents(resource_path('views/livewire/conversation-messages.blade.php'));

        expect($source)->toContain('navigator.clipboard.writeText')
            // The single Livewire call in this view is the edit save.
            ->and(substr_count($source, '$wire.'))->toBe(1)
            ->and($source)->toContain('$wire.updateMessage')
            // Success swaps only the copied message icon, then reverts back.
            ->and($source)->toContain('copiedId')
            ->and($source)->toContain('setTimeout')
            ->and($source)->toContain("x-text=\"copiedId === '")
            ->and($source)->toContain('تم النسخ');
    });

    test('inline editor is a tall single-line input, not a textarea', function () {
        $source = file_get_contents(resource_path('views/livewire/conversation-messages.blade.php'));

        expect($source)->toContain('data-edit-input')
            ->and($source)->toContain('<input type="text"')
            ->and($source)->toContain('px-3.5 py-6')
            ->and($source)->not()->toContain('<textarea')
            ->and($source)->toContain('@keydown.enter="saveEdit($el)"');
    });

    test('editor spans the message width while the bubble keeps its size', function () {
        $source = file_get_contents(resource_path('views/livewire/conversation-messages.blade.php'));

        // Wide editor column with the bubble capped to its previous size,
        // so long bubbles render exactly as before.
        expect($source)->toContain('w-full max-w-[95%]')
            ->and($source)->toContain('max-w-[82%] sm:max-w-[74%]');
    });

    test('edit mode fully replaces the bubble and actions in place', function () {
        $source = file_get_contents(resource_path('views/livewire/conversation-messages.blade.php'));

        // Both the message bubble and its action row hide while editing, so
        // no original text stays visible above, beside, or behind the input.
        expect(substr_count($source, 'x-show="editingId !=='))->toBe(2)
            ->and($source)->toContain('x-show="editingId ===');
    });
});

describe('saving an edit', function () {
    test('valid edit persists exactly once with no new message and no reload', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'النص الأصلي',
        ]);
        $this->actingAs($user);

        $component = Livewire::test(ConversationMessages::class, ['conversation' => $conversation])
            ->call('updateMessage', (string) $message->id, 'النص المعدل')
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('close-edit-message');

        expect($component->effects['redirect'] ?? null)->toBeNull()
            ->and(Message::count())->toBe(1)
            ->and($message->refresh()->content)->toBe('النص المعدل');

        // The re-rendered list shows the server-authoritative content.
        $component->assertSee('النص المعدل', false);
    });

    test('invalid edit keeps the original content and reports Arabic validation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'النص الأصلي',
        ]);
        $this->actingAs($user);

        Livewire::test(ConversationMessages::class, ['conversation' => $conversation])
            ->call('updateMessage', (string) $message->id, '   ')
            ->assertDispatched('toast', type: 'error', title: 'تعذر الحفظ', message: 'حقل الرسالة مطلوب.')
            ->assertNotDispatched('close-edit-message');

        expect(Message::count())->toBe(1)
            ->and($message->refresh()->content)->toBe('النص الأصلي');
    });

    test('editing another users message is forbidden', function () {
        $user = User::factory()->create();
        $mine = Conversation::factory()->create(['user_id' => $user->id]);
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'رسالة سرية',
        ]);

        $this->actingAs($user);

        Livewire::test(ConversationMessages::class, ['conversation' => $mine])
            ->call('updateMessage', (string) $message->id, 'محاولة')
            ->assertForbidden();

        expect($message->refresh()->content)->toBe('رسالة سرية');
    });

    test('assistant messages cannot be edited even with a forged request', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رد المساعد',
        ]);
        $this->actingAs($user);

        Livewire::test(ConversationMessages::class, ['conversation' => $conversation])
            ->call('updateMessage', (string) $message->id, 'محاولة')
            ->assertForbidden();

        expect($message->refresh()->content)->toBe('رد المساعد');
    });
});
