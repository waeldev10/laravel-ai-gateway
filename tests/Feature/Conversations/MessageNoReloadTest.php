<?php

use App\Enums\MessageRole;
use App\Livewire\Chat\ConversationMessages;
use App\Livewire\Chat\MessageComposer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('sending a message in an existing conversation', function () {
    test('sending persists the user message and the assistant reply with no redirect and no reload', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        config()->set('ai.provider', 'openai');
        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.model', 'gpt-4o-mini');
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'رد المساعد']]],
            ], 200),
        ]);

        $component = Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', 'رسالة بدون تحديث للصفحة')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('message-sent')
            ->assertSet('content', '');

        // No redirect effect: the page stays mounted, no browser reload.
        expect($component->effects['redirect'] ?? null)->toBeNull();

        $messages = Message::orderBy('id')->get();

        expect($messages)->toHaveCount(2);

        expect($messages[0]->content)->toBe('رسالة بدون تحديث للصفحة')
            ->and($messages[0]->role)->toBe(MessageRole::User)
            ->and($messages[0]->conversation_id)->toBe($conversation->id)
            ->and($messages[1]->role)->toBe(MessageRole::Assistant)
            ->and($messages[1]->content)->toBe('رد المساعد');
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
