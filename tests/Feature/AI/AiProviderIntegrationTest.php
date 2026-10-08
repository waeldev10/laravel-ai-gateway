<?php

use App\AI\AiService;
use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiAuthenticationException;
use App\AI\Exceptions\AiAuthorizationException;
use App\AI\Exceptions\AiConnectionException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Providers\GLM\GlmProvider;
use App\AI\Providers\OpenAI\OpenAiProvider;
use App\AI\Providers\OpenCode\OpenCodeProvider;
use App\AI\Providers\OpenRouter\OpenRouterProvider;
use App\AI\Support\AiProviderResolver;
use App\Enums\MessageRole;
use App\Livewire\Chat\MessageComposer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\MessageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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

    // No test in this file may reach a real external API.
    Http::preventStrayRequests();
});

function fakeChatSuccess(string $pattern, string $text = 'رد المساعد'): void
{
    Http::fake([
        $pattern => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
        ], 200),
    ]);
}

function fakeResponsesSuccess(string $pattern, string $text = 'رد المساعد'): void
{
    Http::fake([
        $pattern => Http::response([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
        ], 200),
    ]);
}

describe('provider resolution', function () {
    test('each provider resolves correctly', function (string $name, string $class) {
        config()->set('ai.provider', $name);

        expect(app(AiProviderResolver::class)->resolve())->toBeInstanceOf($class)
            ->and(app(AiProviderResolver::class)->resolve())->toBeInstanceOf(AiProvider::class);
    })->with([
        ['openai', OpenAiProvider::class],
        ['gemini', GeminiProvider::class],
        ['openrouter', OpenRouterProvider::class],
        ['opencode', OpenCodeProvider::class],
        ['glm', GlmProvider::class],
    ]);

    test('an unsupported provider fails clearly', function () {
        config()->set('ai.provider', 'unknown');

        expect(fn () => app(AiProviderResolver::class)->resolve())
            ->toThrow(AiProviderException::class, 'Unsupported AI provider');
    });

    test('application code resolves the contract through the container', function () {
        config()->set('ai.provider', 'openai');

        expect(app(AiProvider::class))->toBeInstanceOf(OpenAiProvider::class);

        config()->set('ai.provider', 'glm');

        expect(app(AiProvider::class))->toBeInstanceOf(GlmProvider::class);
    });
});

describe('provider configuration', function () {
    test('all five providers expose key, model, base url, and timeout configuration', function () {
        foreach (['openai', 'gemini', 'openrouter', 'opencode', 'glm'] as $name) {
            expect(config("ai.providers.{$name}.api_key"))->not()->toBeEmpty()
                ->and(config("ai.providers.{$name}.model"))->not()->toBeEmpty()
                ->and(config("ai.providers.{$name}.base_url"))->toStartWith('https://')
                ->and(config("ai.providers.{$name}.timeout"))->toBeInt();
        }
    });
});

describe('openai provider', function () {
    test('a successful response returns the assistant text', function () {
        fakeChatSuccess('https://api.openai.com/*', 'أهلاً بك');

        $text = app(OpenAiProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request['model'] === 'gpt-4o-mini'
                && $request['messages'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('an invalid response fails clearly', function () {
        Http::fake(['https://api.openai.com/*' => Http::response(['choices' => []], 200)]);

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiInvalidResponseException::class);
    });

    test('an authentication failure fails clearly', function () {
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'invalid key'], 401)]);

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiAuthenticationException::class);
    });

    test('a rate limit fails clearly with the retry hint', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => '120'])]);

        try {
            app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

            $this->fail('Expected an AiRateLimitException.');
        } catch (AiRateLimitException $e) {
            expect($e->retryAfterSeconds())->toBe(120)
                ->and($e->userMessage())->not()->toBe('');
        }
    });

    test('a connection failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(Http::failedConnection());

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiConnectionException::class);
    });

    test('a server failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://api.openai.com/*' => Http::response('error', 500)]);

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiUnavailableException::class);
    });

    test('missing configuration fails clearly without an http request', function () {
        config()->set('ai.providers.openai.api_key', null);

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiProviderException::class);

        Http::assertNothingSent();
    });
});

describe('gemini provider', function () {
    test('a successful response returns the assistant text', function () {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'أهلاً بك']]]]],
            ], 200),
        ]);

        $text = app(GeminiProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
            ['role' => 'assistant', 'content' => 'أهلاً'],
            ['role' => 'user', 'content' => 'وكيف حالك؟'],
        ]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent')
                && $request['contents'] === [
                    ['role' => 'user', 'parts' => [['text' => 'مرحبا']]],
                    ['role' => 'model', 'parts' => [['text' => 'أهلاً']]],
                    ['role' => 'user', 'parts' => [['text' => 'وكيف حالك؟']]],
                ];
        });
    });

    test('an invalid response fails clearly', function () {
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['candidates' => []], 200)]);

        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiInvalidResponseException::class);
    });

    test('an authentication failure fails clearly', function () {
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad key'], 400)]);

        // A 400 from the provider is a rejected request, surfaced generically.
        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiProviderException::class);
    });

    test('a rate limit fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'slow down'], 429)]);

        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiRateLimitException::class);
    });

    test('a connection failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(Http::failedConnection());

        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiConnectionException::class);
    });

    test('a server failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response('error', 503)]);

        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiUnavailableException::class);
    });
});

describe('openrouter provider', function () {
    test('a successful response returns the assistant text through the openrouter endpoint', function () {
        fakeChatSuccess('https://openrouter.ai/*', 'أهلاً بك');

        $text = app(OpenRouterProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->toPsrRequest()->getHeaderLine('Authorization') === 'Bearer test-openrouter-key'
                && $request['model'] === 'openrouter/free'
                && $request['messages'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('optional ranking headers are sent only when configured', function () {
        config()->set('ai.providers.openrouter.referer', 'https://example.test');
        config()->set('ai.providers.openrouter.title', 'Example');
        fakeChatSuccess('https://openrouter.ai/*');

        app(OpenRouterProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        Http::assertSent(function ($request) {
            $psr = $request->toPsrRequest();

            return $psr->getHeaderLine('HTTP-Referer') === 'https://example.test'
                && $psr->getHeaderLine('X-Title') === 'Example';
        });
    });

    test('an invalid response fails clearly', function () {
        Http::fake(['https://openrouter.ai/*' => Http::response(['choices' => []], 200)]);

        expect(fn () => app(OpenRouterProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiInvalidResponseException::class);
    });

    test('an authentication failure fails clearly', function () {
        Http::fake(['https://openrouter.ai/*' => Http::response(['error' => 'invalid key'], 401)]);

        expect(fn () => app(OpenRouterProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiAuthenticationException::class);
    });

    test('a rate limit fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://openrouter.ai/*' => Http::response(['error' => 'slow down'], 429)]);

        expect(fn () => app(OpenRouterProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiRateLimitException::class);
    });

    test('a connection failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(Http::failedConnection());

        // The failed-connection stub rejects every host, including OpenRouter's.
        expect(fn () => app(OpenRouterProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiConnectionException::class);
    });

    test('a server failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://openrouter.ai/*' => Http::response('error', 500)]);

        expect(fn () => app(OpenRouterProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiUnavailableException::class);
    });
});

describe('opencode provider', function () {
    test('a successful response returns the assistant text through the zen responses endpoint', function () {
        fakeResponsesSuccess('https://opencode.ai/*', 'أهلاً بك');

        $text = app(OpenCodeProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://opencode.ai/zen/v1/responses'
                && $request['model'] === 'muse-spark-1.3-contributor-free'
                && $request['input'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('an invalid responses payload fails clearly', function () {
        Http::fake(['https://opencode.ai/*' => Http::response(['output' => []], 200)]);

        expect(fn () => app(OpenCodeProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiInvalidResponseException::class);
    });

    test('an authentication failure fails clearly', function () {
        Http::fake(['https://opencode.ai/*' => Http::response(['error' => 'invalid key'], 401)]);

        expect(fn () => app(OpenCodeProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiAuthenticationException::class);
    });

    test('a rate limit fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://opencode.ai/*' => Http::response(['error' => 'slow down'], 429)]);

        expect(fn () => app(OpenCodeProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiRateLimitException::class);
    });

    test('a connection failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(Http::failedConnection());

        expect(fn () => app(OpenCodeProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiConnectionException::class);
    });

    test('a server failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://opencode.ai/*' => Http::response('error', 500)]);

        expect(fn () => app(OpenCodeProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiUnavailableException::class);
    });
});

describe('protocol selection', function () {
    test('opencode can switch to chat completions through configuration alone', function () {
        config()->set('ai.providers.opencode.protocol', 'chat_completions');
        fakeChatSuccess('https://opencode.ai/*', 'توافق مفتوح');

        $text = app(OpenCodeProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('توافق مفتوح');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://opencode.ai/zen/v1/chat/completions'
                && $request['messages'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('openai can share the responses protocol through configuration alone', function () {
        config()->set('ai.providers.openai.protocol', 'responses');
        fakeResponsesSuccess('https://api.openai.com/*', 'مشترك');

        $text = app(OpenAiProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('مشترك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['model'] === 'gpt-4o-mini'
                && $request['input'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('an unknown protocol fails clearly without an http request', function () {
        config()->set('ai.providers.openai.protocol', 'carrier_pigeon');

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiProviderException::class, 'does not support');

        Http::assertNothingSent();
    });

    test('a protocol the provider does not support fails clearly', function () {
        config()->set('ai.providers.gemini.protocol', 'responses');

        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiProviderException::class, 'does not support');

        Http::assertNothingSent();
    });

    test('a timeout is distinguished from a plain connection failure', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds'));

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiTimeoutException::class);
    });
});

describe('provider error diagnostics', function () {
    test('a 403 is authorization, not authentication, and is never retried', function () {
        config()->set('ai.retry.max_attempts', 3);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => ['message' => 'Model access forbidden', 'code' => 'model_forbidden']], 403)]);

        try {
            app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

            $this->fail('Expected an AiAuthorizationException.');
        } catch (AiAuthorizationException $e) {
            expect($e->userMessage())->not()->toBe('')
                ->and($e->detail())->toContain('model_forbidden')
                ->and($e->detail())->toContain('Model access forbidden');
        }

        Http::assertSentCount(1);
    });

    test('an opencode 403 preserves the sanitized provider reason', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://opencode.ai/*' => Http::response(['error' => ['message' => 'Insufficient credits', 'code' => 'billing_required']], 403)]);

        try {
            app(OpenCodeProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

            $this->fail('Expected an AiAuthorizationException.');
        } catch (AiAuthorizationException $e) {
            expect($e->provider())->toBe('OpenCode')
                ->and($e->status())->toBe(403)
                ->and($e->detail())->toContain('billing_required');
        }
    });

    test('sanitized diagnostics never leak into user-facing messages', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']], 401)]);

        try {
            app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

            $this->fail('Expected an AiAuthenticationException.');
        } catch (AiAuthenticationException $e) {
            expect($e->detail())->toContain('invalid_api_key')
                ->and($e->userMessage())->not()->toContain('Incorrect API key');
        }
    });
});

describe('architecture boundaries', function () {
    test('message service knows no provider protocols, endpoints, or credentials', function () {
        $source = file_get_contents(app_path('Services/Message/MessageService.php'));

        foreach (['chat/completions', '/responses', 'generateContent', 'Bearer', 'choices', 'candidates', 'openai', 'gemini', 'openrouter', 'opencode', 'glm', 'muse-spark'] as $fragment) {
            expect($source)->not()->toContain($fragment);
        }
    });

    test('ai service contains no provider or model branches', function () {
        $source = file_get_contents(app_path('AI/AiService.php'));

        foreach (['openai', 'gemini', 'openrouter', 'opencode', 'glm', 'chat/completions', '/responses', 'generateContent', 'muse-spark'] as $fragment) {
            expect($source)->not()->toContain($fragment);
        }
    });

    test('model names are configuration data, never php conditionals', function () {
        $files = File::allFiles(app_path('AI'));

        expect($files)->not()->toBeEmpty();

        foreach ($files as $file) {
            $source = file_get_contents($file->getPathname());

            foreach (['muse-spark', 'gpt-4', 'gpt-5', 'gemini-2', 'gemini-3', 'glm-4', 'glm-5', 'kimi-', 'big-pickle'] as $model) {
                expect($source)->not()->toContain($model);
            }
        }
    });

    test('the abstract provider references no concrete protocol', function () {
        $source = file_get_contents(app_path('AI/Providers/AbstractAiProvider.php'));

        foreach (['ChatCompletionsProtocol', 'ResponsesProtocol', 'GenerateContentProtocol'] as $protocol) {
            expect($source)->not()->toContain($protocol);
        }
    });
});

describe('glm provider', function () {
    test('a successful response returns the assistant text through the z.ai endpoint', function () {
        fakeChatSuccess('https://api.z.ai/*', 'أهلاً بك');

        $text = app(GlmProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
                && $request['model'] === 'glm-5'
                && $request['messages'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('an invalid response fails clearly', function () {
        Http::fake(['https://api.z.ai/*' => Http::response(['choices' => []], 200)]);

        expect(fn () => app(GlmProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiInvalidResponseException::class);
    });

    test('an authentication failure fails clearly', function () {
        Http::fake(['https://api.z.ai/*' => Http::response(['error' => 'invalid key'], 401)]);

        expect(fn () => app(GlmProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiAuthenticationException::class);
    });

    test('a rate limit fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://api.z.ai/*' => Http::response(['error' => 'slow down'], 429)]);

        expect(fn () => app(GlmProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiRateLimitException::class);
    });

    test('a connection failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(Http::failedConnection());

        expect(fn () => app(GlmProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiConnectionException::class);
    });

    test('a server failure fails clearly', function () {
        config()->set('ai.retry.max_attempts', 1);
        Http::fake(['https://api.z.ai/*' => Http::response('error', 500)]);

        expect(fn () => app(GlmProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiUnavailableException::class);
    });
});

describe('retry policy', function () {
    test('a transient server error is retried and then succeeds', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push(['error' => 'blip'], 500)
                ->push(['choices' => [['message' => ['content' => 'بعد المحاولة']]]], 200),
        ]);

        $text = app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('بعد المحاولة');

        Http::assertSentCount(2);
    });

    test('a rate limit is retried and then succeeds', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push(['error' => 'slow down'], 429)
                ->push(['choices' => [['message' => ['content' => 'بعد الحد']]]], 200),
        ]);

        $text = app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('بعد الحد');

        Http::assertSentCount(2);
    });

    test('an authentication failure is never retried', function () {
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'invalid key'], 401)]);

        expect(fn () => app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiAuthenticationException::class);

        Http::assertSentCount(1);
    });

    test('a custom base url is honored for openai-compatible endpoints', function () {
        config()->set('ai.providers.openai.base_url', 'https://custom.example.test/v1');
        Http::fake([
            'https://custom.example.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'مخصص']]],
            ], 200),
        ]);

        $text = app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('مخصص');

        Http::assertSent(fn ($request) => $request->url() === 'https://custom.example.test/v1/chat/completions');
    });
});

describe('ai service', function () {
    test('messages are passed to the configured provider and normalized text is returned', function () {
        fakeChatSuccess('https://api.openai.com/*', 'نص موحد');

        $text = app(AiService::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('نص موحد');

        Http::assertSent(fn ($request) => $request['messages'] === [['role' => 'user', 'content' => 'مرحبا']]);
    });

    test('provider exceptions propagate as application-level ai exceptions', function () {
        config()->set('ai.provider', 'unsupported');

        expect(fn () => app(AiService::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiException::class);
    });
});

describe('ai message flow', function () {
    test('a user message persists, the provider is called, and the assistant reply persists', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeChatSuccess('https://api.openai.com/*', 'رد الذكاء');

        $assistant = app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال المستخدم');

        expect($assistant->role)->toBe(MessageRole::Assistant)
            ->and($assistant->content)->toBe('رد الذكاء');

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]->role)->toBe(MessageRole::User)
            ->and($messages[0]->content)->toBe('سؤال المستخدم')
            ->and($messages[1]->role)->toBe(MessageRole::Assistant)
            ->and($messages[1]->content)->toBe('رد الذكاء');
    });

    test('the full conversation history reaches the provider', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => 'الأولى',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => 'أهلاً',
        ]);
        fakeChatSuccess('https://api.openai.com/*', 'تفضل');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية');

        Http::assertSent(fn ($request) => $request['messages'] === [
            ['role' => 'user', 'content' => 'الأولى'],
            ['role' => 'assistant', 'content' => 'أهلاً'],
            ['role' => 'user', 'content' => 'الثانية'],
        ]);
    });

    test('a provider failure keeps the user message, creates no assistant message, and raises safely', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response('error', 500)]);

        expect(fn () => app(MessageService::class)->sendUserMessage($user, $conversation, 'سؤال ضائع؟'))
            ->toThrow(AiException::class);

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->role)->toBe(MessageRole::User)
            ->and($messages[0]->content)->toBe('سؤال ضائع؟');
    });

    test('authorization rules remain intact for the ai flow', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);
        fakeChatSuccess('https://api.openai.com/*');

        expect(fn () => app(MessageService::class)->sendUserMessage($user, $conversation, 'رسالة دخيلة'))
            ->toThrow(AuthorizationException::class);

        expect(Message::count())->toBe(0);

        Http::assertNothingSent();
    });
});

describe('application rate limiting', function () {
    test('generations under the limit succeed normally', function () {
        config()->set('ai.rate_limit.max_attempts', 5);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

        expect($conversation->messages()->count())->toBe(2);
    });

    test('exceeding the limit keeps the user message, creates no reply, and reports retry timing', function () {
        config()->set('ai.rate_limit.max_attempts', 1);
        config()->set('ai.retry.max_attempts', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakeChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

        try {
            app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية');

            $this->fail('Expected an AiRateLimitException.');
        } catch (AiRateLimitException $e) {
            expect($e->retryAfterSeconds())->not()->toBeNull()
                ->and($e->userMessage())->not()->toBe('');
        }

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(3)
            ->and($messages->where('role', MessageRole::Assistant))->toHaveCount(1);
    });
});

describe('ai composer flow', function () {
    test('sending in an existing conversation persists both messages with a success toast', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        fakeChatSuccess('https://api.openai.com/*', 'رد حي');

        Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', 'سؤال حي')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('message-sent')
            ->assertSet('content', '');

        expect($conversation->messages()->count())->toBe(2);
    });

    test('a provider failure in the composer keeps the user message and shows a safe error', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        Http::fake(['https://api.openai.com/*' => Http::response('error', 500)]);

        Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', 'سؤال فاشل')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'error')
            ->assertDispatched('message-sent')
            ->assertSet('content', '');

        $messages = $conversation->messages()->orderBy('id')->get();

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->content)->toBe('سؤال فاشل');
    });

    test('the legacy post route shares the same flow including provider failure', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response('error', 500)]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), ['content' => 'رسالة عبر النموذج'])
            ->assertRedirect(route('conversations.show', $conversation))
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error');

        expect(Message::count())->toBe(1)
            ->and(Message::first()->content)->toBe('رسالة عبر النموذج');
    });
});
