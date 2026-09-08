<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('chat page rendering', function () {
    test('the chat page renders the conversation title and back button', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'title' => 'محادثة الواجهة',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('محادثة الواجهة')
            ->assertSee('العودة إلى المحادثات')
            ->assertSee('href="'.route('conversations.index').'"', false);
    });

    test('an empty conversation renders the empty state', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('لا توجد رسائل بعد');
    });

    test('user messages render with the user alignment', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'رسالة المستخدم',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('رسالة المستخدم')
            ->assertSee('justify-end', false);
    });

    test('assistant messages render with the assistant alignment', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رسالة المساعد',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('رسالة المساعد')
            ->assertSee('justify-start', false);
    });

    test('messages remain in chronological order', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'الأولى',
            'created_at' => now()->subHours(2),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'الثانية',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSeeInOrder(['الأولى', 'الثانية']);
    });
});

describe('chat composer', function () {
    test('the chat page includes a message form', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('action="'.route('conversations.messages.store', $conversation).'"', false)
            ->assertSee('textarea', false)
            ->assertSee('إرسال');
    });

    test('the composer keeps the failed content in the textarea', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $tooLong = str_repeat('أ', 10001);

        $this->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->post(route('conversations.messages.store', $conversation), [
                'content' => $tooLong,
            ])
            ->assertSessionHasErrors('content');

        expect(session('_old_input')['content'] ?? null)->toBe($tooLong);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('maxlength="10000"', false);
    });

    test('sending a message still uses the existing backend flow end to end', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->post(route('conversations.messages.store', $conversation), [
                'content' => 'رسالة عبر الواجهة',
            ])
            ->assertRedirect(route('conversations.show', $conversation));

        $message = Message::first();

        expect($message)->not()->toBeNull()
            ->and($message->content)->toBe('رسالة عبر الواجهة')
            ->and($message->role)->toBe(MessageRole::User);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('رسالة عبر الواجهة');
    });
});

describe('chat page security', function () {
    test('unauthorized conversation access remains blocked', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'رسالة سرية',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertForbidden()
            ->assertDontSee('رسالة سرية');
    });
});
