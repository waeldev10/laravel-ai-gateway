<?php

use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiUsageLimitException;
use App\AI\Support\AiRetryPolicy;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Models\AiMemory;
use App\Models\AiPersona;
use App\Models\AiRequest;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiContextService;
use App\Services\AI\AiPromptService;
use App\Services\AI\AiService;
use App\Services\AI\AiUsageService;
use App\Services\Audit\AuditLogService;
use App\Services\Message\MessageService;
use App\Support\SafeMarkdown;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

if (! function_exists('fakeFoundationChatSuccess')) {
    function fakeFoundationChatSuccess(string $pattern, string $text = 'رد المساعد'): void
    {
        Http::fake([
            $pattern => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            ], 200),
        ]);
    }
}

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.rate_limit.max_attempts', 1000);
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);
    config()->set('ai.usage.enabled', true);
    config()->set('ai.usage.window_minutes', 10);
    config()->set('ai.usage.max_requests', 1000);
    config()->set('ai.usage.cooldown_minutes', 5);
    config()->set('ai.context.history_limit', 20);
    config()->set('ai.context.memory_limit', 5);
    config()->set('ai.prompt.default', '');

    foreach (['openai', 'gemini', 'openrouter', 'opencode', 'glm'] as $provider) {
        config()->set("ai.providers.{$provider}.api_key", "test-{$provider}-key");
        config()->set("ai.providers.{$provider}.timeout", 30);
    }

    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');

    Http::preventStrayRequests();
});

describe('application ai service location', function () {
    test('the application service is usable without any channel concerns', function () {
        fakeFoundationChatSuccess('https://api.openai.com/*', 'نص مباشر');

        $text = app(AiService::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('نص مباشر');
    });

    test('the legacy namespace remains a compatible alias', function () {
        expect(app(App\AI\AiService::class))->toBeInstanceOf(AiService::class);
    });

    test('the application service and message service know no channel or provider internals', function () {
        $aiSource = file_get_contents(app_path('Services/AI/AiService.php'));

        foreach (['openai', 'gemini', 'openrouter', 'opencode', 'glm', 'chat/completions', '/responses', 'generateContent', 'Livewire', 'Telegram'] as $fragment) {
            expect($aiSource)->not()->toContain($fragment);
        }

        $messageSource = file_get_contents(app_path('Services/Message/MessageService.php'));

        foreach (['Livewire', 'Telegram', 'Blade', 'chat/completions', '/responses', 'generateContent', 'Bearer'] as $fragment) {
            expect($messageSource)->not()->toContain($fragment);
        }
    });
});

describe('request sources', function () {
    test('unknown source identifiers fall back to web instead of persisting raw input', function () {
        expect(AiSource::fromRaw('bogus-channel'))->toBe(AiSource::Web)
            ->and(AiSource::fromRaw('TELEGRAM'))->toBe(AiSource::Telegram)
            ->and(AiSource::fromRaw(null))->toBe(AiSource::Web);
    });

    test('web requests are recorded with their source', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال', AiSource::Web);

        expect(AiRequest::query()->value('source'))->toBe(AiSource::Web);
    });

    test('api and telegram requests share the same service and record their source', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'من API', AiSource::Api);
        app(MessageService::class)->sendUserMessage($user, $conversation, 'من تيليجرام', AiSource::Telegram);

        $sources = AiRequest::query()->orderBy('created_at')->pluck('source')->all();

        expect($sources)->toBe([AiSource::Api, AiSource::Telegram]);
    });
});

describe('application usage limit and cooldown', function () {
    test('usage under the limit succeeds normally', function () {
        config()->set('ai.usage.max_requests', 5);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

        expect($conversation->messages()->count())->toBe(2)
            ->and(app(AiUsageService::class)->remainingCooldown($user))->toBeNull();
    });

    test('exhausting the window blocks usage without calling the provider', function () {
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

        try {
            app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية');

            $this->fail('Expected an AiUsageLimitException.');
        } catch (AiUsageLimitException $e) {
            expect($e->retryAfterSeconds())->toBeGreaterThan(0)
                ->and($e->userMessage())->not()->toBe('');
        }

        Http::assertSentCount(1);

        // The blocked user message persists (same guarantee as provider
        // failures) but no second assistant reply is created.
        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(3)
            ->and($messages->where('role', MessageRole::Assistant))->toHaveCount(1);

        expect(AiRequest::query()->where('status', AiRequestStatus::Blocked)->count())->toBe(1);
    });

    test('cooldown stays enforced until it expires, then usage is allowed again', function () {
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

        expect(fn () => app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية'))
            ->toThrow(AiUsageLimitException::class);

        $remaining = app(AiUsageService::class)->remainingCooldown($user);

        expect($remaining)->not()->toBeNull()->and($remaining)->toBeGreaterThan(0);

        // After both cooldown and window expire, usage is allowed again.
        $this->travel(16)->minutes();

        expect(app(AiUsageService::class)->remainingCooldown($user))->toBeNull();

        app(MessageService::class)->sendUserMessage($user, $conversation, 'بعد الانتظار');

        expect($conversation->messages()->where('role', MessageRole::Assistant)->count())->toBe(2);
    });

    test('a blocked regeneration truncates nothing', function () {
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال');

        $assistant = $conversation->messages()->where('role', MessageRole::Assistant)->firstOrFail();
        $countBefore = $conversation->messages()->count();

        $generator = app(MessageService::class)->regenerateReply($user, $conversation, $assistant);

        expect(fn () => iterator_to_array($generator, false))
            ->toThrow(AiUsageLimitException::class);

        expect($conversation->messages()->count())->toBe($countBefore);

        Http::assertSentCount(1);
    });

    test('usage can be disabled through configuration', function () {
        config()->set('ai.usage.enabled', false);
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        AiRequest::factory()->create(['user_id' => $user->id, 'status' => AiRequestStatus::Completed]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'رغم الحد');

        expect($conversation->messages()->where('role', MessageRole::Assistant)->count())->toBe(1);
    });
});

describe('separation of limit concepts', function () {
    test('a provider rate limit stays separate from the application usage limit', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'slow down'], 429)]);

        try {
            app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال');

            $this->fail('Expected an AiRateLimitException.');
        } catch (AiRateLimitException $e) {
            expect($e)->not()->toBeInstanceOf(AiUsageLimitException::class);
        }

        // No usage cooldown is activated by an upstream 429.
        expect(app(AiUsageService::class)->remainingCooldown($user))->toBeNull();

        $failed = AiRequest::query()->where('status', AiRequestStatus::Failed)->firstOrFail();

        expect($failed->error_type)->toBe('AiRateLimitException');
    });

    test('the retry policy never retries application usage blocks', function () {
        $policy = app(AiRetryPolicy::class);

        expect($policy->shouldRetry(new AiUsageLimitException('blocked', 60), 0))->toBeFalse();
    });
});

describe('ai request records', function () {
    test('a successful request is recorded with operational metadata only', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'رد');

        $assistant = app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال');

        $record = AiRequest::query()->firstOrFail();

        expect($record->user_id)->toBe((string) $user->getKey())
            ->and($record->source)->toBe(AiSource::Web)
            ->and($record->provider)->toBe('openai')
            ->and($record->model)->toBe('gpt-4o-mini')
            ->and($record->conversation_id)->toBe((string) $conversation->getKey())
            ->and($record->message_id)->toBe((string) $assistant->getKey())
            ->and($record->status)->toBe(AiRequestStatus::Completed)
            ->and($record->started_at)->not()->toBeNull()
            ->and($record->completed_at)->not()->toBeNull()
            ->and($record->duration_ms)->not()->toBeNull();

        $attributes = array_keys($record->getAttributes());

        foreach (['content', 'prompt', 'completion', 'api_key', 'headers'] as $sensitive) {
            expect($attributes)->not()->toContain($sensitive);
        }
    });

    test('a failed request records its sanitized error type', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response('error', 500)]);

        try {
            app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال ضائع؟');

            $this->fail('Expected an AI exception.');
        } catch (AiRateLimitException|AiUsageLimitException $e) {
            $this->fail('Expected a provider failure, got: '.get_class($e));
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(AiException::class);
        }

        $record = AiRequest::query()->firstOrFail();

        expect($record->status)->toBe(AiRequestStatus::Failed)
            ->and($record->error_type)->not()->toBeNull()
            ->and($record->message_id)->toBeNull();
    });
});

describe('audit logging', function () {
    test('a successful generation audits the request lifecycle', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال');

        $actions = AuditLog::query()->pluck('action')->all();

        expect($actions)->toContain(AuditLogService::MESSAGE_SENT)
            ->and($actions)->toContain(AuditLogService::AI_REQUEST_STARTED)
            ->and($actions)->toContain(AuditLogService::AI_REQUEST_COMPLETED);

        $completed = AuditLog::query()->where('action', AuditLogService::AI_REQUEST_COMPLETED)->firstOrFail();

        expect($completed->user_id)->toBe((string) $user->getKey())
            ->and($completed->source)->toBe(AiSource::Web)
            ->and($completed->result)->toBe('success')
            ->and($completed->metadata['provider'] ?? null)->toBe('openai')
            ->and($completed->metadata['conversation_id'] ?? null)->toBe((string) $conversation->getKey());
    });

    test('usage blocks and cooldown activation are audited', function () {
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

        expect(fn () => app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية'))
            ->toThrow(AiUsageLimitException::class);

        $actions = AuditLog::query()->pluck('action')->all();

        expect($actions)->toContain(AuditLogService::AI_USAGE_BLOCKED)
            ->and($actions)->toContain(AuditLogService::AI_COOLDOWN_ACTIVATED);
    });

    test('provider failures are audited without sensitive data', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response('error', 500)]);

        try {
            app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال');
        } catch (Throwable) {
        }

        $failed = AuditLog::query()->where('action', AuditLogService::AI_REQUEST_FAILED)->firstOrFail();

        expect($failed->result)->toBe('failed')
            ->and($failed->metadata)->not()->toHaveKey('content');

        expect(AuditLog::query()->where('action', AuditLogService::PROVIDER_FAILURE)->count())->toBe(1);
    });

    test('audit metadata sanitization drops secrets and keeps identifiers', function () {
        $record = app(AuditLogService::class)->record(
            User::factory()->create(),
            AiSource::Web,
            'ai.request_completed',
            'success',
            [
                'provider' => 'openai',
                'api_key' => 'sk-secret',
                'Authorization' => 'Bearer secret',
                'content' => 'full prompt must never persist',
                'password' => 'hunter2',
            ]
        );

        expect($record->metadata)->toBe(['provider' => 'openai']);
    });
});

describe('prompt and persona resolution', function () {
    test('no persona resolves to an empty set by default', function () {
        $user = User::factory()->create();

        expect(app(AiPromptService::class)->effectivePersonas($user))->toBeEmpty()
            ->and(app(AiPromptService::class)->effectivePersonas(null))->toBeEmpty();
    });

    test('a system default persona applies when the user has none', function () {
        AiPersona::factory()->create(['user_id' => null, 'system_prompt' => 'You are a senior Laravel developer.']);
        $user = User::factory()->create();

        $effective = app(AiPromptService::class)->effectivePersonas($user);

        expect($effective)->toHaveCount(1)
            ->and($effective->first()->system_prompt)->toBe('You are a senior Laravel developer.');
    });

    test('default comes first with active customs appended in order', function () {
        AiPersona::factory()->create(['user_id' => null, 'system_prompt' => 'System default.', 'priority' => 10]);
        $user = User::factory()->create();
        AiPersona::factory()->create(['user_id' => $user->id, 'system_prompt' => 'User persona.', 'priority' => 1]);

        $texts = app(AiPromptService::class)->effectivePersonas($user)->pluck('system_prompt')->all();

        expect($texts)->toBe(['System default.', 'User persona.']);
    });

    test('the configured fallback applies when no persona exists', function () {
        config()->set('ai.prompt.default', 'Helpful assistant.');
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $built = app(AiContextService::class)->build($user, $conversation);

        expect($built[0])->toBe(['role' => 'system', 'content' => 'Helpful assistant.']);
    });

    test('personas are isolated per user with ownership enforced', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $system = AiPersona::factory()->create(['user_id' => null, 'system_prompt' => 'System.']);
        $own = AiPersona::factory()->create(['user_id' => $user->id, 'system_prompt' => 'Mine.']);
        AiPersona::factory()->create(['user_id' => $other->id, 'system_prompt' => 'Theirs.']);

        $visible = app(AiPromptService::class)->listFor($user)->pluck('id')->all();

        expect($visible)->toContain((string) $system->getKey())
            ->and($visible)->toContain((string) $own->getKey());

        foreach (AiPersona::query()->where('user_id', $other->id)->pluck('id')->all() as $foreignId) {
            expect($visible)->not()->toContain((string) $foreignId);
        }

        expect(fn () => app(AiPromptService::class)->updateFor($user, $system, ['name' => 'x']))
            ->toThrow(AuthorizationException::class);

        $foreign = AiPersona::query()->where('user_id', $other->id)->firstOrFail();

        expect(fn () => app(AiPromptService::class)->deleteFor($user, $foreign))
            ->toThrow(AuthorizationException::class);
    });
});

describe('conversation context and memory', function () {
    test('history passes through unchanged when no persona or memory exists', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => 'الأولى']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::Assistant, 'content' => 'أهلاً']);

        $built = app(AiContextService::class)->build($user, $conversation);

        expect($built)->toBe([
            ['role' => 'user', 'content' => 'الأولى'],
            ['role' => 'assistant', 'content' => 'أهلاً'],
        ]);
    });

    test('persona and memory are prepended as provider-agnostic system messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        AiPersona::factory()->create(['user_id' => $user->id, 'system_prompt' => 'You are helpful.']);
        AiMemory::factory()->create(['user_id' => $user->id, 'content' => 'The user prefers Arabic.']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => 'مرحبا']);

        $built = app(AiContextService::class)->build($user, $conversation);

        expect($built[0])->toBe(['role' => 'system', 'content' => 'You are helpful.'])
            ->and($built[1]['role'])->toBe('system')
            ->and($built[1]['content'])->toContain('The user prefers Arabic.')
            ->and($built[2])->toBe(['role' => 'user', 'content' => 'مرحبا']);
    });

    test('history is bounded and memory is isolated per user', function () {
        config()->set('ai.context.history_limit', 20);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 25; $i++) {
            Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => "رسالة {$i}"]);
        }

        AiMemory::factory()->create(['user_id' => $other->id, 'content' => 'Foreign secret fact.']);

        $built = app(AiContextService::class)->build($user, $conversation);

        expect($built)->toHaveCount(20)
            ->and($built[0]['content'])->toBe('رسالة 5')
            ->and(implode(' ', array_column($built, 'content')))->not()->toContain('Foreign secret fact.');
    });

    test('context for another user conversation is refused', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);

        expect(fn () => app(AiContextService::class)->build($user, $conversation))
            ->toThrow(AuthorizationException::class);
    });

    test('usage counting is isolated per user', function () {
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $other = User::factory()->create();
        AiRequest::factory()->create(['user_id' => $other->id, 'status' => AiRequestStatus::Completed]);

        expect(app(AiUsageService::class)->windowCount($user))->toBe(0);
    });
});

describe('safe ai rendering', function () {
    test('markdown structures render while dangerous html cannot execute', function () {
        $html = SafeMarkdown::render("## مقارنة\n\n- item 1\n- item 2\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n```php\necho 'hi';\n```\n\n`inline` **bold**");

        expect($html)->toContain('<h2>')
            ->and($html)->toContain('<ul>')
            ->and($html)->toContain('<table>')
            ->and($html)->toContain('<pre>')
            ->and($html)->toContain('<code>')
            ->and($html)->toContain('<strong>');
    });

    test('scripts, event handlers, and javascript links are neutralized', function () {
        $html = SafeMarkdown::render("<script>alert('xss')</script>\n\n<img src=\"x\" onerror=\"alert(1)\">\n\n[click](javascript:alert(1))\n\n<div onclick=\"alert(1)\">hi</div>");

        expect($html)->not()->toContain('<script')
            ->and($html)->not()->toContain('onerror')
            ->and($html)->not()->toContain('onclick')
            ->and($html)->not()->toContain('javascript:');
    });

    test('assistant messages render markdown in the chat view', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => "- item 1\n- item 2",
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('<ul>', false)
            ->assertSee('<li>', false);
    });
});

describe('user isolation', function () {
    test('one user cannot generate on or read another user ai records', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        fakeFoundationChatSuccess('https://api.openai.com/*', 'رد');

        expect(fn () => app(MessageService::class)->sendUserMessage($user, $conversation, 'دخيلة'))
            ->toThrow(AuthorizationException::class);

        expect(AiRequest::query()->count())->toBe(0);

        Http::assertNothingSent();
    });
});
