<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedHistory(Conversation $conversation, int $count, string $prefix = 'history message'): void
{
    for ($i = 1; $i <= $count; $i++) {
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => $i % 2 === 0 ? MessageRole::Assistant : MessageRole::User,
            'content' => "{$prefix} {$i}",
        ]);
    }
}

function historyService(): MessageService
{
    return app(MessageService::class);
}

describe('initial message load', function () {
    test('opening a conversation does not load the entire message history', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 50);

        $html = $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->getContent();

        // Exactly the latest page is rendered — never all 50 nodes.
        // (Assistant messages repeat their id on action buttons, so the
        // unique rendered message ids are counted.)
        preg_match_all('/data-message-id="([^"]+)"/', $html, $matches);
        expect(array_unique($matches[1] ?? []))->toHaveCount(30);
    });

    test('initial load contains at most 30 messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 50);

        $page = historyService()->latestPageFor($user, $conversation);

        expect($page['messages'])->toHaveCount(30)
            ->and($page['hasMore'])->toBeTrue()
            ->and($page['oldestCursor'])->not()->toBeNull();
    });

    test('conversations with fewer than 30 messages load all available messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 5);

        $page = historyService()->latestPageFor($user, $conversation);

        expect($page['messages'])->toHaveCount(5)
            ->and($page['hasMore'])->toBeFalse()
            ->and($page['oldestCursor'])->toBe((string) $page['messages']->first()->getKey());

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('data-has-more="0"', false);
    });

    test('long conversations expose has-more state for the history loader', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 31);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('data-has-more="1"', false)
            ->assertSee('data-history-loader', false)
            ->assertSee('data-chat-history', false);
    });
});

describe('cursor-based pagination', function () {
    test('older messages can be loaded using a cursor', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 50);

        $first = historyService()->latestPageFor($user, $conversation);
        $second = historyService()->olderPageFor($user, $conversation, $first['oldestCursor']);

        expect($second['messages'])->toHaveCount(20)
            ->and($second['hasMore'])->toBeFalse()
            ->and($second['oldestCursor'])->toBe((string) $second['messages']->first()->getKey());
    });

    test('the second page contains messages older than the first page', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 65);

        $first = historyService()->latestPageFor($user, $conversation);
        $second = historyService()->olderPageFor($user, $conversation, $first['oldestCursor']);

        $firstIds = $first['messages']->map(fn (Message $m) => (string) $m->getKey())->all();
        $secondIds = $second['messages']->map(fn (Message $m) => (string) $m->getKey())->all();

        expect($second['messages'])->toHaveCount(30)
            ->and($second['hasMore'])->toBeTrue();

        foreach ($secondIds as $id) {
            expect(strcmp($id, min($firstIds)))->toBeLessThan(0);
        }

        expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
    });

    test('pages are returned oldest-first for display', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 40);

        $page = historyService()->latestPageFor($user, $conversation);
        $ids = $page['messages']->map(fn (Message $m) => (string) $m->getKey())->all();
        $sorted = $ids;

        sort($sorted);

        expect($ids)->toBe($sorted);
    });

    test('hasMoreMessages becomes false when there are no more messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 65);

        $first = historyService()->latestPageFor($user, $conversation);
        $second = historyService()->olderPageFor($user, $conversation, $first['oldestCursor']);
        $third = historyService()->olderPageFor($user, $conversation, $second['oldestCursor']);

        expect($first['hasMore'])->toBeTrue()
            ->and($second['hasMore'])->toBeTrue()
            ->and($third['messages'])->toHaveCount(5)
            ->and($third['hasMore'])->toBeFalse();

        $fourth = historyService()->olderPageFor($user, $conversation, $third['oldestCursor']);

        expect($fourth['messages'])->toHaveCount(0)
            ->and($fourth['hasMore'])->toBeFalse()
            ->and($fourth['oldestCursor'])->toBeNull();
    });

    test('no duplicate messages are inserted across pages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 65);

        $all = [];
        $cursor = null;

        do {
            $page = $cursor === null
                ? historyService()->latestPageFor($user, $conversation)
                : historyService()->olderPageFor($user, $conversation, $cursor);

            foreach ($page['messages'] as $message) {
                $all[] = (string) $message->getKey();
            }

            $cursor = $page['oldestCursor'];
            $hasMore = $page['hasMore'];
        } while ($hasMore);

        expect($all)->toHaveCount(65)
            ->and(array_unique($all))->toHaveCount(65);
    });

    test('repeated requests with the same cursor return identical results', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 50);

        $first = historyService()->latestPageFor($user, $conversation);
        $again = historyService()->olderPageFor($user, $conversation, $first['oldestCursor']);
        $repeat = historyService()->olderPageFor($user, $conversation, $first['oldestCursor']);

        $ids = fn (array $page) => collect($page['messages'])->map(fn (Message $m) => (string) $m->getKey())->all();

        expect($ids($repeat))->toBe($ids($again));
    });

    test('no OFFSET pagination is used for message history', function () {
        $source = file_get_contents(app_path('Services/Message/MessageService.php'));

        expect($source)->toContain('HISTORY_PAGE_SIZE')
            ->and($source)->toContain("where('id', '<'")
            ->and($source)->toContain('orderByDesc')
            ->and($source)->not()->toContain('->offset(')
            ->and($source)->not()->toContain('->skip(')
            ->and($source)->not()->toContain('->paginate(')
            ->and($source)->not()->toContain('->simplePaginate(');
    });

    test('the initial chat-loading path never loads the full history', function () {
        $controller = file_get_contents(app_path('Http/Controllers/ConversationController.php'));
        $component = file_get_contents(app_path('Livewire/Chat/ConversationMessages.php'));

        expect($controller)->toContain('latestPageFor')
            ->and($controller)->not()->toContain('listFor')
            ->and($component)->toContain('latestPageFor')
            ->and($component)->not()->toContain('listFor');
    });

    test('no message-history cache was added', function () {
        $service = file_get_contents(app_path('Services/Message/MessageService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/MessageController.php'));

        expect($service)->not()->toContain('Cache::')
            ->and($controller)->not()->toContain('Cache::');
    });
});

describe('history endpoint', function () {
    test('the latest page is returned without a cursor', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 35);

        $response = $this->actingAs($user)
            ->getJson(route('conversations.messages.index', $conversation))
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'role', 'content', 'html']], 'has_more', 'oldest_cursor']);

        expect($response->json('data'))->toHaveCount(30)
            ->and($response->json('has_more'))->toBeTrue()
            ->and($response->json('oldest_cursor'))->toBe($response->json('data.0.id'));
    });

    test('older pages are returned with a cursor and identical markup', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 35, 'paged message');

        $first = $this->actingAs($user)
            ->getJson(route('conversations.messages.index', $conversation))
            ->assertOk();

        $second = $this->actingAs($user)
            ->getJson(route('conversations.messages.index', [$conversation, 'before' => $first->json('oldest_cursor')]))
            ->assertOk();

        expect($second->json('data'))->toHaveCount(5)
            ->and($second->json('has_more'))->toBeFalse();

        $firstIds = collect($first->json('data'))->pluck('id')->all();

        foreach ($second->json('data') as $item) {
            expect($item['id'])->not()->toBeIn($firstIds);
            expect($item['html'])->toContain('data-message-id="'.$item['id'].'"');
        }
    });

    test('an empty conversation returns an empty page', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson(route('conversations.messages.index', $conversation))
            ->assertOk();

        expect($response->json('data'))->toBeEmpty()
            ->and($response->json('has_more'))->toBeFalse()
            ->and($response->json('oldest_cursor'))->toBeNull();
    });

    test('history access is authorized like the conversation itself', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        seedHistory($conversation, 3);

        // Guests first: actingAs() below would persist for later calls.
        $this->getJson(route('conversations.messages.index', $conversation))
            ->assertUnauthorized();

        $this->actingAs($user)
            ->getJson(route('conversations.messages.index', $conversation))
            ->assertForbidden();
    });

    test('page size is bounded', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 40);

        $this->actingAs($user)
            ->getJson(route('conversations.messages.index', [$conversation, 'limit' => 31]))
            ->assertStatus(422);

        $this->actingAs($user)
            ->getJson(route('conversations.messages.index', [$conversation, 'limit' => 10]))
            ->assertOk()
            ->assertJsonCount(10, 'data');
    });

    test('loading older messages does not reload the sidebar, theme, or composer', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 35);

        $response = $this->actingAs($user)
            ->getJson(route('conversations.messages.index', $conversation))
            ->assertOk();

        $content = $response->getContent();

        expect($content)->not()->toContain('data-chat-composer')
            ->and($content)->not()->toContain('chat-sidebar')
            ->and($content)->not()->toContain('__setTheme');

        $client = file_get_contents(resource_path('js/chat-history.js'));

        expect($client)->toContain('loadingOlderMessages')
            ->and($client)->toContain('oldestMessageCursor')
            ->and($client)->toContain('hasMoreMessages')
            ->and($client)->toContain('scrollTop')
            ->and($client)->toContain('insertAdjacentHTML')
            ->and($client)->toContain('fetch(')
            ->and($client)->not()->toContain('Livewire.dispatch')
            ->and($client)->not()->toContain('location.reload')
            ->and($client)->not()->toContain('localStorage')
            ->and($client)->not()->toContain('__setTheme');
    });
});

describe('message sending and streaming still work', function () {
    test('existing message sending still works with paginated history', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 35, 'older paged message');

        config()->set('ai.provider', 'openai');
        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.model', 'gpt-4o-mini');
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'رد المساعد']]],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), ['content' => 'رسالة جديدة بعد التقسيم'])
            ->assertRedirect(route('conversations.show', $conversation));

        expect(Message::where('conversation_id', $conversation->id)->count())->toBe(37);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('رسالة جديدة بعد التقسيم');
    });

    test('existing streaming behavior still works with paginated history', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 31, 'older paged message');

        config()->set('ai.provider', 'openai');
        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.model', 'gpt-4o-mini');
        config()->set('ai.providers.openai.protocol', 'chat_completions');
        config()->set('ai.rate_limit.max_attempts', 30);
        config()->set('ai.retry.max_attempts', 1);
        config()->set('ai.retry.base_sleep_ms', 0);
        config()->set('ai.retry.max_sleep_ms', 0);

        $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello']]]])."\n\n";
        $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => ' world']]]])."\n\n";
        $sse .= "data: [DONE]\n\n";

        Http::fake([
            'https://api.openai.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'سؤال بعد التقسيم']);

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

        $events = [];
        foreach (explode("\n\n", $response->streamedContent()) as $frame) {
            $frame = trim($frame);
            if (str_starts_with($frame, 'data:')) {
                $decoded = json_decode(trim(substr($frame, 5)), true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        }

        expect($events[count($events) - 1]['type'])->toBe('done');
        expect(Message::where('conversation_id', $conversation->id)->count())->toBe(33);
    });
});

describe('performance verification', function () {
    test('a 500-message conversation loads in bounded pages without overlap', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 500);

        $service = historyService();
        $first = $service->latestPageFor($user, $conversation);

        // Initial load: only 30 messages, never the whole conversation.
        expect($first['messages'])->toHaveCount(30)
            ->and($first['hasMore'])->toBeTrue();

        $all = [];
        $cursor = null;
        $requests = 0;

        do {
            $page = $cursor === null
                ? $service->latestPageFor($user, $conversation)
                : $service->olderPageFor($user, $conversation, $cursor);

            foreach ($page['messages'] as $message) {
                $all[] = (string) $message->getKey();
            }

            $cursor = $page['oldestCursor'];
            $hasMore = $page['hasMore'];
            $requests++;
        } while ($hasMore);

        // Previously loaded pages are never requested again: exactly
        // ceil(500 / 30) = 17 page requests cover the whole history once.
        expect($requests)->toBe(17)
            ->and($all)->toHaveCount(500)
            ->and(array_unique($all))->toHaveCount(500);
    });

    test('the initial chat-loading path issues only bounded messages queries', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        seedHistory($conversation, 60);

        $messageQueries = [];

        DB::listen(function ($query) use (&$messageQueries) {
            if (str_contains($query->sql, 'messages')) {
                $messageQueries[] = $query->sql;
            }
        });

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk();

        // Controller + Livewire initial paint: every query against the
        // messages table carries a LIMIT (the 31-row probe) — no full
        // history load remains on this path.
        expect($messageQueries)->not()->toBeEmpty();

        foreach ($messageQueries as $sql) {
            expect(strtolower($sql))->toContain('limit');
        }
    });
});

describe('rest api untouched', function () {
    test('no REST API was added or changed', function () {
        $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();
        $apiRoutes = array_values(array_filter($uris, fn (string $uri) => str_starts_with($uri, 'api/')));

        expect($apiRoutes)->toBeEmpty()
            ->and(file_exists(base_path('routes/api.php')))->toBeFalse();
    });
});
