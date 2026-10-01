<?php

use App\Livewire\Chat\ConversationMessages;
use App\Livewire\Chat\MessageComposer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('sending a message in an existing conversation', function () {
    test('sending persists exactly once with no redirect and no reload', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', 'رسالة بدون تحديث للصفحة')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('message-sent')
            ->assertSet('content', '');

        // No redirect effect: the page stays mounted, no browser reload.
        expect($component->effects['redirect'] ?? null)->toBeNull();

        expect(Message::count())->toBe(1);

        $message = Message::first();

        expect($message->content)->toBe('رسالة بدون تحديث للصفحة')
            ->and($message->conversation_id)->toBe($conversation->id);
    });

    test('the message list refreshes from persisted state after message-sent', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $component = Livewire::test(ConversationMessages::class, ['conversation' => $conversation])
            ->assertSee('ابدأ محادثتك الأولى');

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'رسالة لاحقة حقيقية',
        ]);

        // The same event the composer dispatches re-renders from the service.
        $component->call('refreshList')->assertSee('رسالة لاحقة حقيقية', false);
    });

    test('validation still rejects empty content with the Arabic message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', '')
            ->call('send')
            ->assertHasErrors(['content']);

        expect($component->errors()->get('content'))->toBe(['حقل الرسالة مطلوب.'])
            ->and(Message::count())->toBe(0);
    });

    test('the composer carries no redirect or location-changing behavior', function () {
        $component = file_get_contents(app_path('Livewire/Chat/MessageComposer.php'));
        $view = file_get_contents(resource_path('views/livewire/Chat/message-composer.blade.php'));

        expect($component)->not()->toContain('redirectRoute')
            ->and($component)->not()->toContain('location.')
            ->and($view)->not()->toContain('wire:navigate')
            ->and($view)->not()->toContain('location.');
    });
});

describe('first message navigation distinction', function () {
    test('new-chat send still navigates to the real conversation exactly once', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class)
            ->set('content', 'أول رسالة')
            ->call('send')
            ->assertHasNoErrors();

        expect(Conversation::count())->toBe(1)
            ->and(Message::count())->toBe(1);

        $conversation = Conversation::first();

        $component->assertRedirect(route('conversations.show', $conversation));

        expect($conversation->messages()->first()->content)->toBe('أول رسالة');
    });
});
