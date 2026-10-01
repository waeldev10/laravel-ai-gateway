<?php

use App\Livewire\Chat\MessageComposer;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversation\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('new chat first message', function () {
    test('empty new chat shows a single centered composer and no starters', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('conversations.create'))
            ->assertOk()
            ->assertSee('محادثة جديدة')
            ->assertSee('اسأل أي شيء...', false)
            ->getContent();

        expect($html)->not()->toContain('اشرح لي Laravel')
            ->and(substr_count($html, '<textarea'))->toBe(1);
    });

    test('startFor creates one conversation with the exact first message and a short title', function () {
        $user = User::factory()->create();
        $content = 'كيف أتعامل مع العلاقات بين الجداول في Laravel؟';

        $conversation = app(ConversationService::class)->startFor($user, $content);

        expect(Conversation::count())->toBe(1)
            ->and(Message::count())->toBe(1)
            ->and($conversation->user_id)->toBe($user->id)
            ->and($conversation->messages()->first()->content)->toBe($content)
            ->and($conversation->messages()->first()->role->value)->toBe('user')
            ->and(mb_strlen($conversation->title))->toBeLessThan(mb_strlen($content))
            ->and($conversation->title)->not()->toBe($content);
    });

    test('composer start mode creates everything and navigates to the real conversation', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class)
            ->set('content', 'ساعدني في كتابة كود PHP للمصادقة')
            ->call('send')
            ->assertHasNoErrors();

        $conversation = Conversation::first();

        expect($conversation)->not()->toBeNull();
        $component->assertRedirect(route('conversations.show', $conversation));

        // Opening the conversation directly loads the same persisted message.
        $this->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('ساعدني في كتابة كود PHP للمصادقة', false);

        // Sidebar reflects the new conversation with its title.
        Livewire::test(SidebarConversations::class)
            ->assertSee($conversation->title, false);
    });

    test('empty first message stays on new chat with errors and creates nothing', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(MessageComposer::class)
            ->set('content', '')
            ->call('send')
            ->assertHasErrors(['content']);

        expect(Conversation::count())->toBe(0)
            ->and(Message::count())->toBe(0);
    });

    test('title generation stays short and never empty', function () {
        $service = app(ConversationService::class);

        $long = 'اشرح لي بالتفصيل الممل كيفية بناء نظام مصادقة كامل باستخدام Laravel Sanctum مع أفضل الممارسات والنصائح الأمنية المهمة جداً';
        $title = $service->titleFor($long);

        expect($title)->not()->toBe($long)
            ->and(mb_strlen($title))->toBeLessThanOrEqual(61)
            ->and($service->titleFor('   '))->toBe('محادثة جديدة')
            ->and($service->titleFor('مرحبا'))->toBe('مرحبا');
    });
});
