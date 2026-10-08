<?php

use App\AI\AiService;
use App\AI\Exceptions\AiAuthenticationException;
use App\AI\Exceptions\AiException;
use App\AI\Protocols\ChatCompletionsProtocol;
use App\AI\Protocols\GenerateContentProtocol;
use App\AI\Protocols\ResponsesProtocol;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\MessageService;
use Illuminate\Auth\Access\AuthorizationException;
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

    foreach (['openai', 'gemini', 'openrouter', 'opencode', 'glm'] as $provider) {
        config()->set("ai.providers.{$provider}.api_key", "test-{$provider}-key");
        config()->set("ai.providers.{$provider}.timeout", 30);
    }

    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.gemini.model', 'gemini-2.0-flash');
    config()->set('ai.providers.openrouter.model', 'openrouter/free');
    config()->set('ai.providers.opencode.model', 'muse-spark-1.3-contributor-free');
    config()->set('ai.providers.glm.model', 'glm-5');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.providers.gemini.protocol', 'generate_content');
    config()->set('ai.providers.openrouter.protocol', 'chat_completions');
    config()->set('ai.providers.opencode.protocol', 'responses');
    config()->set('ai.providers.glm.protocol', 'chat_completions');

    Http::preventStrayRequests();
});

function fakeChatStream(string $pattern, array $chunks): void
{
    $sse = '';

    foreach ($chunks as $chunk) {
        $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $chunk]]]])."\n\n";
    }

    $sse .= "data: [DONE]\n\n";

    Http::fake([$pattern => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
}

function fakeResponsesStream(string $pattern, array $chunks): void
{
    $sse = '';

    foreach ($chunks as $chunk) {
        $sse .= 'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => $chunk])."\n\n";
    }

    $sse .= 'event: response.completed'."\n".'data: '.json_encode(['type' => 'response.completed'])."\n\n";

    Http::fake([$pattern => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
}

function streamEvents(string $content): array
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

describe('streaming endpoint', function () {
    test('it returns an SSE stream with delta and done events', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeChatStream('https://api.openai.com/*', ['Hello', ' world']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'مرحبا']);

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

        $events = streamEvents($response->streamedContent());
        $deltas = array_values(array_filter($events, fn ($e) => ($e['type'] ?? null) === 'delta'));

        expect($deltas)->toHaveCount(2)
            ->and($deltas[0]['text'])->toBe('Hello')
            ->and($deltas[1]['text'])->toBe(' world')
            ->and($events[count($events) - 1]['type'])->toBe('done');
    });

    test('authorization is enforced and nothing is sent upstream', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        fakeChatStream('https://api.openai.com/*', ['x']);

        $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'دخيلة'])
            ->assertForbidden();

        expect(Message::count())->toBe(0);

        Http::assertNothingSent();
    });

    test('guests are redirected to login', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->post(route('conversations.messages.stream', $conversation), ['content' => 'hi'])
            ->assertRedirect(route('login'));
    });

    test('user message persists and chunks are forwarded progressively', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeChatStream('https://api.openai.com/*', ['Lar', 'avel']);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'سؤال']);

        $events = streamEvents($response->streamedContent());
        $texts = array_map(
            fn ($e) => $e['text'],
            array_values(array_filter($events, fn ($e) => ($e['type'] ?? null) === 'delta'))
        );

        expect(implode('', $texts))->toBe('Laravel');

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]->role)->toBe(MessageRole::User)
            ->and($messages[0]->content)->toBe('سؤال')
            ->and($messages[1]->role)->toBe(MessageRole::Assistant)
            ->and($messages[1]->content)->toBe('Laravel');
    });

    test('final assistant message is persisted exactly once, not per chunk', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeChatStream('https://api.openai.com/*', ['a', 'b', 'c', 'd']);

        app(MessageService::class)->createUserMessage($user, $conversation, 'عدّ');

        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_contains(strtolower($query->sql), 'insert into "messages"')
                || str_contains(strtolower($query->sql), 'insert into `messages`')
                || str_contains(strtolower($query->sql), 'insert into messages')) {
                $inserts++;
            }
        });

        $generator = app(MessageService::class)->streamReply($user, $conversation);
        $deltas = iterator_to_array($generator, false);
        $assistant = $generator->getReturn();

        expect($deltas)->toBe(['a', 'b', 'c', 'd'])
            ->and($assistant->content)->toBe('abcd')
            ->and($inserts)->toBe(1)
            ->and($conversation->messages()->where('role', MessageRole::Assistant)->count())->toBe(1);
    });

    test('new-conversation streaming creates the conversation and returns a redirect', function () {
        $user = User::factory()->create();
        fakeChatStream('https://api.openai.com/*', ['أهلا']);

        $response = $this->actingAs($user)
            ->post(route('conversations.stream'), ['content' => 'أول رسالة']);

        $response->assertOk();

        $events = streamEvents($response->streamedContent());
        $done = collect($events)->firstWhere('type', 'done');

        expect(Conversation::count())->toBe(1)
            ->and(Message::count())->toBe(2)
            ->and($done['redirect_url'] ?? null)->toStartWith(url('/conversations/'));
    });
});

describe('streaming errors', function () {
    test('a 401 becomes a safe SSE error with no assistant message and no retry', function () {
        config()->set('ai.retry.max_attempts', 3);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'bad key'], 401)]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);

        $events = streamEvents($response->streamedContent());
        $error = collect($events)->firstWhere('type', 'error');

        expect($error['message'] ?? '')->not()->toBe('')
            ->and($error['message'] ?? '')->not()->toContain('bad key');

        expect($conversation->messages()->count())->toBe(1)
            ->and($conversation->messages()->first()->role)->toBe(MessageRole::User);

        Http::assertSentCount(1);
    });

    test('a 403 is never retried', function () {
        config()->set('ai.retry.max_attempts', 3);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'forbidden'], 403)]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);

        expect(collect(streamEvents($response->streamedContent()))->firstWhere('type', 'error'))->not()->toBeNull();

        Http::assertSentCount(1);
    });

    test('a 429 is retried and then succeeds', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push(['error' => 'slow'], 429)
                ->push("data: {\"choices\":[{\"delta\":{\"content\":\"بعد\"}}]}\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);

        $events = streamEvents($response->streamedContent());

        expect(collect($events)->firstWhere('type', 'done'))->not()->toBeNull()
            ->and($conversation->messages()->where('role', MessageRole::Assistant)->first()->content)->toBe('بعد');

        Http::assertSentCount(2);
    });

    test('a 5xx is retried and then succeeds', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push(['error' => 'blip'], 500)
                ->push("data: {\"choices\":[{\"delta\":{\"content\":\"عاد\"}}]}\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);
        $response->assertOk();

        $events = streamEvents($response->streamedContent());

        expect(collect($events)->firstWhere('type', 'done'))->not()->toBeNull()
            ->and($conversation->messages()->where('role', MessageRole::Assistant)->first()->content)->toBe('عاد');

        Http::assertSentCount(2);

        Http::assertSentCount(2);
    });

    test('an empty stream fails safely with no assistant message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response("data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);

        $events = streamEvents($response->streamedContent());

        expect(collect($events)->firstWhere('type', 'error'))->not()->toBeNull()
            ->and($conversation->messages()->count())->toBe(1);
    });

    test('a malformed-only stream fails safely', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response("data: not-json{{{\n\ndata: [DONE]\n\n", 200)]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);

        expect(collect(streamEvents($response->streamedContent()))->firstWhere('type', 'error'))->not()->toBeNull()
            ->and($conversation->messages()->count())->toBe(1);
    });
});

describe('streaming protocols', function () {
    test('chat completions parses deltas and the done marker', function () {
        $protocol = app(ChatCompletionsProtocol::class);

        expect($protocol->extractDelta('data: {"choices":[{"delta":{"content":"Hi"}}]}'."\n"))->toBe('Hi')
            ->and($protocol->extractDelta(": heartbeat\n"))->toBeNull()
            ->and($protocol->extractDelta('data: [DONE]'."\n"))->toBeNull()
            ->and($protocol->isStreamDone("data: [DONE]\n\n"))->toBeTrue()
            ->and($protocol->isStreamDone('data: {"choices":[]}'."\n"))->toBeFalse()
            ->and($protocol->streamPayload([['role' => 'user', 'content' => 'hi']], ['model' => 'm'])['stream'])->toBeTrue();
    });

    test('responses protocol parses output text deltas only', function () {
        $protocol = app(ResponsesProtocol::class);

        expect($protocol->extractDelta('data: {"type":"response.output_text.delta","delta":"مرحبا"}'."\n"))->toBe('مرحبا')
            ->and($protocol->extractDelta('data: {"type":"response.function_call_arguments.delta","delta":"{}"}'."\n"))->toBeNull()
            ->and($protocol->isStreamDone("event: response.completed\n"))->toBeTrue();
    });

    test('generate content parses candidate text', function () {
        $protocol = app(GenerateContentProtocol::class);

        expect($protocol->extractDelta('data: {"candidates":[{"content":{"parts":[{"text":"هلا"}]}}]}'."\n"))->toBe('هلا')
            ->and(str_contains($protocol->streamEndpoint(['base_url' => 'https://x', 'model' => 'm']), ':streamGenerateContent'))->toBeTrue();
    });

    test('openrouter streams through the shared chat completions protocol', function () {
        config()->set('ai.provider', 'openrouter');
        fakeChatStream('https://openrouter.ai/*', ['a', 'b']);

        $deltas = iterator_to_array(app(AiService::class)->stream([['role' => 'user', 'content' => 'hi']]), false);

        expect(implode('', $deltas))->toBe('ab');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request['stream'] === true;
        });
    });

    test('opencode responses default streams end to end', function () {
        config()->set('ai.provider', 'opencode');
        fakeResponsesStream('https://opencode.ai/*', ['Hel', 'lo']);

        $text = implode('', iterator_to_array(app(AiService::class)->stream([['role' => 'user', 'content' => 'hi']]), false));

        expect($text)->toBe('Hello');
    });

    test('provider stream failures surface as ai exceptions', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://api.openai.com/*' => Http::response('x', 500)]);

        expect(fn () => iterator_to_array(app(AiService::class)->stream([['role' => 'user', 'content' => 'hi']]), false))
            ->toThrow(AiException::class);
    });

    test('authentication failures on stream are never retried', function () {
        config()->set('ai.retry.max_attempts', 3);
        Http::fake(['https://api.openai.com/*' => Http::response('x', 401)]);

        expect(fn () => iterator_to_array(app(AiService::class)->stream([['role' => 'user', 'content' => 'hi']]), false))
            ->toThrow(AiAuthenticationException::class);

        Http::assertSentCount(1);
    });

    test('unauthorized conversation access never reaches the provider', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        fakeChatStream('https://api.openai.com/*', ['x']);

        expect(fn () => iterator_to_array(app(MessageService::class)->streamReply($user, $conversation), false))
            ->toThrow(AuthorizationException::class);

        Http::assertNothingSent();
    });
});
