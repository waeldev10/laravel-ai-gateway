<?php

use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiAuthenticationException;
use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiUnavailableException;
use App\AI\Providers\DeepSeek\DeepSeekProvider;
use App\AI\Support\AiProviderResolver;
use App\Services\AI\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'deepseek');
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);

    config()->set('ai.providers.deepseek.api_key', 'test-deepseek-key');
    config()->set('ai.providers.deepseek.model', 'deepseek-chat');
    config()->set('ai.providers.deepseek.base_url', 'https://api.deepseek.com');
    config()->set('ai.providers.deepseek.protocol', 'chat_completions');
    config()->set('ai.providers.deepseek.timeout', 30);

    Http::preventStrayRequests();
});

if (! function_exists('fakeDeepSeekChatSuccess')) {
    function fakeDeepSeekChatSuccess(string $pattern, string $text = 'رد المساعد'): void
    {
        Http::fake([
            $pattern => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            ], 200),
        ]);
    }
}

if (! function_exists('fakeDeepSeekChatStream')) {
    function fakeDeepSeekChatStream(string $pattern, array $chunks): void
    {
        $sse = '';

        foreach ($chunks as $chunk) {
            $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $chunk]]]])."\n\n";
        }

        $sse .= "data: [DONE]\n\n";

        Http::fake([$pattern => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    }
}

describe('deepseek provider resolution', function () {
    test('deepseek resolves through the existing resolver and contract', function () {
        config()->set('ai.provider', 'deepseek');

        expect(app(AiProviderResolver::class)->resolve())->toBeInstanceOf(DeepSeekProvider::class)
            ->and(app(AiProviderResolver::class)->resolve('deepseek'))->toBeInstanceOf(AiProvider::class);
    });

    test('the application service generates through deepseek when configured', function () {
        config()->set('ai.provider', 'deepseek');
        fakeDeepSeekChatSuccess('https://api.deepseek.com/*', 'أهلاً بك');

        $text = app(AiService::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('أهلاً بك');
    });
});

describe('deepseek chat completions', function () {
    test('it uses the configured base url, model, and messages by default', function () {
        fakeDeepSeekChatSuccess('https://api.deepseek.com/*', 'أهلاً بك');

        $text = app(DeepSeekProvider::class)->generate([
            ['role' => 'user', 'content' => 'مرحبا'],
        ]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.deepseek.com/chat/completions'
                && $request['model'] === 'deepseek-chat'
                && $request['messages'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('a custom base url is honored without code changes', function () {
        config()->set('ai.providers.deepseek.base_url', 'https://custom.example.test');
        Http::fake([
            'https://custom.example.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'مخصص']]],
            ], 200),
        ]);

        $text = app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('مخصص');

        Http::assertSent(fn ($request) => $request->url() === 'https://custom.example.test/chat/completions');
    });

    test('authentication headers come from the existing protocol', function () {
        fakeDeepSeekChatSuccess('https://api.deepseek.com/*');

        app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        Http::assertSent(function ($request) {
            return $request->toPsrRequest()->getHeaderLine('Authorization') === 'Bearer test-deepseek-key';
        });
    });

    test('it can switch to the responses protocol through configuration alone', function () {
        config()->set('ai.providers.deepseek.protocol', 'responses');
        Http::fake([
            'https://api.deepseek.com/*' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'مشترك']],
                ]],
            ], 200),
        ]);

        $text = app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('مشترك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.deepseek.com/responses'
                && $request['model'] === 'deepseek-chat'
                && $request['input'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('streaming reuses the existing chat completions streaming implementation', function () {
        fakeDeepSeekChatStream('https://api.deepseek.com/*', ['Hel', 'lo']);

        $deltas = iterator_to_array(app(DeepSeekProvider::class)->stream([['role' => 'user', 'content' => 'hi']]), false);

        expect(implode('', $deltas))->toBe('Hello');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.deepseek.com/chat/completions'
                && $request['stream'] === true;
        });
    });

    test('the provider declares no transport or wire parsing of its own', function () {
        $source = file_get_contents(app_path('AI/Providers/DeepSeek/DeepSeekProvider.php'));

        foreach (['Http::', 'extractDelta', 'extractText', 'toPsrResponse', 'curl', 'Guzzle', 'file_get_contents'] as $fragment) {
            expect($source)->not()->toContain($fragment);
        }
    });
});

describe('deepseek error normalization', function () {
    test('an authentication failure fails clearly', function () {
        Http::fake(['https://api.deepseek.com/*' => Http::response(['error' => 'invalid key'], 401)]);

        expect(fn () => app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiAuthenticationException::class);
    });

    test('a rate limit fails clearly', function () {
        Http::fake(['https://api.deepseek.com/*' => Http::response(['error' => 'slow down'], 429)]);

        expect(fn () => app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiRateLimitException::class);
    });

    test('a server failure fails clearly', function () {
        Http::fake(['https://api.deepseek.com/*' => Http::response('error', 500)]);

        expect(fn () => app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiUnavailableException::class);
    });

    test('an invalid response fails clearly', function () {
        Http::fake(['https://api.deepseek.com/*' => Http::response(['choices' => []], 200)]);

        expect(fn () => app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiInvalidResponseException::class);
    });

    test('missing configuration fails clearly without an http request', function () {
        config()->set('ai.providers.deepseek.api_key', null);

        expect(fn () => app(DeepSeekProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiProviderException::class);

        Http::assertNothingSent();
    });
});
