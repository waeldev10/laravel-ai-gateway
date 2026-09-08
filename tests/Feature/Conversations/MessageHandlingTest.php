<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('message viewing', function () {
    test('authenticated users can open their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'title' => 'محادثة خاصة',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('محادثة خاصة');
    });

    test('users can view messages belonging to their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'رسالة بداخل محادثتي',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('رسالة بداخل محادثتي');
    });

    test('users cannot view another users conversation messages', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'رسالة سرية',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertForbidden()
            ->assertDontSee('رسالة سرية');

        expect(Message::find($message->id))->not()->toBeNull();
    });

    test('guests cannot access conversation messages', function () {
        $conversation = Conversation::factory()->create();

        $this->get(route('conversations.show', $conversation))
            ->assertRedirect(route('login'));
    });
});

describe('message ordering', function () {
    test('messages are displayed chronologically', function () {
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
            'created_at' => now()->subHour(),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'الثالثة',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSeeInOrder(['الأولى', 'الثانية', 'الثالثة']);
    });
});

describe('message creation', function () {
    test('users can send a message to their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => 'رسالة جديدة',
            ])
            ->assertRedirect(route('conversations.show', $conversation))
            ->assertSessionHas('status');

        $message = Message::first();

        expect($message)->not()->toBeNull()
            ->and($message->content)->toBe('رسالة جديدة')
            ->and($message->conversation_id)->toBe($conversation->id);
    });

    test('a created message has the user role', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => 'رسالة مستخدم',
            ])
            ->assertRedirect();

        expect(Message::first()->role)->toBe(MessageRole::User);
    });

    test('a created message is persisted and displayed', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => 'رسالة محفوظة',
            ])
            ->assertRedirect();

        expect(Message::count())->toBe(1);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('رسالة محفوظة');
    });

    test('the message owner is derived from the authorized conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => 'رسالة',
                'conversation_id' => 999,
                'user_id' => 999,
                'role' => 'assistant',
            ])
            ->assertRedirect();

        $message = Message::first();

        expect($message->conversation_id)->toBe($conversation->id)
            ->and($message->role)->toBe(MessageRole::User);
    });
});

describe('message validation', function () {
    test('an empty message is rejected', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => '',
            ])
            ->assertSessionHasErrors('content');

        expect(Message::count())->toBe(0);
    });

    test('validation failures do not create messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => str_repeat('أ', 10001),
            ])
            ->assertSessionHasErrors('content');

        expect(Message::count())->toBe(0);
    });
});

describe('message authorization', function () {
    test('users cannot send a message to another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), [
                'content' => 'رسالة دخيلة',
            ])
            ->assertForbidden();

        expect(Message::count())->toBe(0);
    });

    test('changing the conversation id in the url cannot bypass authorization', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation->id), [
                'content' => 'رسالة دخيلة',
            ])
            ->assertForbidden();

        expect(Message::count())->toBe(0);
    });

    test('guests cannot send messages', function () {
        $conversation = Conversation::factory()->create();

        $this->post(route('conversations.messages.store', $conversation), [
            'content' => 'رسالة',
        ])->assertRedirect(route('login'));

        expect(Message::count())->toBe(0);
    });
});
