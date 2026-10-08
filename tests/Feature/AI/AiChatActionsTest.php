<?php

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.rate_limit.max_attempts', 30);
    config()->set('ai.retry.max_attempts', 3);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);
    config()->set('ai.providers.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.providers.openai.timeout', 30);

    Http::preventStrayRequests();
});

function fakeChatStreamActions(string $pattern, array $chunks): void
{
    $sse = '';

    foreach ($chunks as $chunk) {
        $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $chunk]]]])."\n\n";
    }

    $sse .= "data: [DONE]\n\n";

    Http::fake([$pattern => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
}

function streamEventsActions(string $content): array
{
    $events = [];

    foreach (explode("\n\n", $content) as $frame) {
        $frame = trim($frame);

        if (! str_starts_with($frame, 'data:')) {
            continue;
        }

        $decoded = json_decode(trim(substr($frame, 5)), true);

        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    return $events;
}

describe('continue generation', function () {
    test('continuation streams new text and appends it with a single update', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'اشرح لارافيل',
        ]);
        $partial = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'لارافيل هو',
            'status' => MessageStatus::Partial,
        ]);
        fakeChatStreamActions('https://api.openai.com/*', [' إطار', ' عمل']);

        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_contains(strtolower($query->sql), 'update "messages"')
                || str_contains(strtolower($query->sql), 'update `messages`')) {
                $updates++;
            }
        });

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.continue', $conversation));

        $response->assertOk();

        $events = streamEventsActions($response->streamedContent());
        $deltas = array_values(array_filter($events, fn ($e) => ($e['type'] ?? null) === 'delta'));

        expect($deltas)->toHaveCount(2)
            ->and(collect($events)->firstWhere('type', 'done')['continued'] ?? null)->toBeTrue()
            ->and($updates)->toBe(1)
            ->and($partial->refresh()->content)->toBe('لارافيل هو إطار عمل')
            ->and($partial->refresh()->status)->toBe(MessageStatus::Complete)
            ->and($conversation->messages()->count())->toBe(2);
    });

    test('continuation sends the partial text back as context in a real AI request', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'سؤال',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'بداية',
            'status' => MessageStatus::Partial,
        ]);
        fakeChatStreamActions('https://api.openai.com/*', [' تتمة']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.continue', $conversation));

        $response->assertOk();
        streamEventsActions($response->streamedContent());

        Http::assertSent(function ($request) {
            return $request['messages'] === [
                ['role' => 'user', 'content' => 'سؤال'],
                ['role' => 'assistant', 'content' => 'بداية'],
            ] && ($request['stream'] ?? null) === true;
        });
    });

    test('continuation without a partial reply fails safely with no new message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'سؤال',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رد مكتمل',
            'status' => MessageStatus::Complete,
        ]);
        fakeChatStreamActions('https://api.openai.com/*', ['x']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.continue', $conversation));

        expect(collect(streamEventsActions($response->streamedContent()))->firstWhere('type', 'error'))->not()->toBeNull()
            ->and($conversation->messages()->count())->toBe(2);

        Http::assertNothingSent();
    });

    test('users cannot continue another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        fakeChatStreamActions('https://api.openai.com/*', ['x']);

        $this->actingAs($user)
            ->post(route('conversations.messages.continue', $conversation))
            ->assertForbidden();

        Http::assertNothingSent();
    });
});

describe('regenerate response', function () {
    test('regenerating an assistant reply truncates later history and streams a fresh reply', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $firstUser = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'السؤال الأول',
        ]);
        $firstAssistant = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'الرد القديم',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'السؤال الثاني',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'الرد الثاني',
        ]);
        fakeChatStreamActions('https://api.openai.com/*', ['رد', ' جديد']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.regenerate', [$conversation, $firstAssistant]));

        $response->assertOk();

        $events = streamEventsActions($response->streamedContent());
        $meta = collect($events)->firstWhere('type', 'meta');

        expect($meta['regenerated'] ?? null)->toBeTrue()
            ->and($meta['removed_message_ids'] ?? [])->toHaveCount(3)
            ->and(collect($events)->firstWhere('type', 'done')['regenerated'] ?? null)->toBeTrue();

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]->getKey())->toBe($firstUser->getKey())
            ->and($messages[1]->role)->toBe(MessageRole::Assistant)
            ->and($messages[1]->content)->toBe('رد جديد')
            ->and($messages[1]->status)->toBe(MessageStatus::Complete);
    });

    test('regenerating from a user message keeps the user message and replaces its reply', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'سؤال',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'قديم',
        ]);
        fakeChatStreamActions('https://api.openai.com/*', ['جديد']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.regenerate', [$conversation, $userMessage]));

        $response->assertOk();
        streamEventsActions($response->streamedContent());

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]->content)->toBe('سؤال')
            ->and($messages[1]->content)->toBe('جديد');
    });

    test('regeneration uses the edited content as the actual input', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'ما هو لارافيل؟',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'رد قديم',
        ]);

        app(MessageService::class)->updateUserMessage($user, $userMessage, 'ما هو لارافيل 13؟');
        fakeChatStreamActions('https://api.openai.com/*', ['رد محدث']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.regenerate', [$conversation, $userMessage->refresh()]));

        $response->assertOk();
        streamEventsActions($response->streamedContent());

        Http::assertSent(function ($request) {
            return $request['messages'] === [
                ['role' => 'user', 'content' => 'ما هو لارافيل 13؟'],
            ];
        });

        expect($conversation->messages()->where('role', MessageRole::Assistant)->first()->content)->toBe('رد محدث');
    });

    test('regeneration streams deltas progressively before done', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'سؤال',
        ]);
        $assistant = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'قديم',
        ]);
        fakeChatStreamActions('https://api.openai.com/*', ['أ', 'ب', 'ج']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.regenerate', [$conversation, $assistant]));

        $response->assertOk();

        $events = streamEventsActions($response->streamedContent());
        $texts = array_map(
            fn ($e) => $e['text'],
            array_values(array_filter($events, fn ($e) => ($e['type'] ?? null) === 'delta'))
        );

        expect($texts)->toBe(['أ', 'ب', 'ج'])
            ->and($events[count($events) - 1]['type'])->toBe('done');
    });

    test('users cannot regenerate another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        $assistant = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'سري',
        ]);
        fakeChatStreamActions('https://api.openai.com/*', ['x']);

        $this->actingAs($user)
            ->post(route('conversations.messages.regenerate', [$conversation, $assistant]))
            ->assertForbidden();

        expect($assistant->refresh()->content)->toBe('سري');

        Http::assertNothingSent();
    });

    test('a message from another conversation is a 404', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $foreign = Message::factory()->create([
            'role' => MessageRole::Assistant,
            'content' => 'أجنبية',
        ]);
        fakeChatStreamActions('https://api.openai.com/*', ['x']);

        $this->actingAs($user)
            ->post(route('conversations.messages.regenerate', [$conversation, $foreign]))
            ->assertNotFound();

        Http::assertNothingSent();
    });
});

describe('message status and share', function () {
    test('new assistant messages default to complete and factories agree', function () {
        $message = Message::factory()->create([
            'role' => MessageRole::Assistant,
            'content' => 'رد',
        ]);

        expect($message->status)->toBe(MessageStatus::Complete)
            ->and($message->refresh()->status)->toBe(MessageStatus::Complete);
    });

    test('completed responses render regenerate and share actions, partial ones render continue', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'مكتمل',
            'status' => MessageStatus::Complete,
        ]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-action="regenerate"')
            ->and($html)->toContain('data-action="share"')
            ->and($html)->not()->toContain('data-action="continue"');

        $conversation->messages()->first()->update(['status' => MessageStatus::Partial]);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-action="continue"')
            ->and($html)->toContain('توقف التوليد قبل الاكتمال');
    });

    test('share uses the native share sheet with a clipboard fallback and exposes no public url', function () {
        $view = file_get_contents(resource_path('views/livewire/Chat/message-item.blade.php'));
        $parent = file_get_contents(resource_path('views/livewire/Chat/conversation-messages.blade.php'));
        $client = file_get_contents(resource_path('js/stream-client.js'));

        expect($view)->toContain('data-action="share"')
            ->and($view)->toContain('data-action="regenerate"')
            ->and($view)->toContain('data-action="continue"')
            ->and(substr_count($parent, "@include('livewire.chat.message-item'"))->toBe(1)
            ->and($client)->toContain('navigator.share')
            ->and($client)->toContain('navigator.clipboard.writeText');

        // Private conversations stay private: guests are sent to login and
        // no public share route exists.
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->get(route('conversations.show', $conversation))->assertRedirect(route('login'));

        expect(collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())->filter()->all())->not()->toContain('conversations.share');
    });
});
