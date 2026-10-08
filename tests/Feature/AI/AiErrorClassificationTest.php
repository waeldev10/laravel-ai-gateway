<?php

use App\AI\Exceptions\AiAuthenticationException;
use App\AI\Exceptions\AiAuthorizationException;
use App\AI\Exceptions\AiContentPolicyException;
use App\AI\Exceptions\AiContextLimitException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiInsufficientBalanceException;
use App\AI\Exceptions\AiInvalidRequestException;
use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiModelNotFoundException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;
use App\AI\Exceptions\AiUsageLimitException;
use App\AI\Providers\OpenAI\OpenAiProvider;
use App\AI\Support\AiErrorPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);

    config()->set('ai.providers.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.providers.openai.timeout', 30);

    Http::preventStrayRequests();
});

if (! function_exists('fakeClassifyError')) {
    function fakeClassifyError(int $status, array|string $body = [], array $headers = []): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response($body, $status, $headers)]);
    }
}

function classifyGenerate(): AiException
{
    try {
        app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        test()->fail('Expected an AI exception.');
    } catch (AiException $e) {
        return $e;
    }
}

describe('status and signal classification', function () {
    test('http 400 maps to an invalid request with an actionable message', function () {
        fakeClassifyError(400, ['error' => ['message' => 'Bad request', 'type' => 'invalid_request_error']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiInvalidRequestException::class)
            ->and($e->userMessage())->toBe('الطلب المرسل إلى مزود الذكاء الاصطناعي غير صالح.');
    });

    test('http 401 maps to authentication with an actionable message', function () {
        fakeClassifyError(401, ['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiAuthenticationException::class)
            ->and($e->userMessage())->toBe('مفتاح API الخاص بمزود الذكاء الاصطناعي غير صالح أو غير صحيح.');
    });

    test('http 402 maps to insufficient balance with an actionable message', function () {
        // DeepSeek-style: 402 with an insufficient-balance signal.
        fakeClassifyError(402, ['error' => ['message' => 'Insufficient Balance', 'type' => 'invalid_request_error']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiInsufficientBalanceException::class)
            ->and($e->userMessage())->toBe('رصيد مزود الذكاء الاصطناعي غير كافٍ لتنفيذ الطلب.');
    });

    test('http 403 maps to authorization with an actionable message', function () {
        fakeClassifyError(403, ['error' => ['message' => 'Forbidden', 'code' => 'model_forbidden']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiAuthorizationException::class)
            ->and($e->userMessage())->toBe('ليس لديك صلاحية لاستخدام هذا النموذج أو هذا المورد.');
    });

    test('http 404 with a model signal maps to model unavailable', function () {
        fakeClassifyError(404, ['error' => ['message' => 'The model `gpt-9` does not exist', 'code' => 'model_not_found']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiModelNotFoundException::class)
            ->and($e->userMessage())->toBe('النموذج المحدد غير متاح لدى مزود الذكاء الاصطناعي. تحقق من اسم النموذج وإعداداته.');
    });

    test('http 400 with a model signal maps to model unavailable, not generic invalid', function () {
        fakeClassifyError(400, ['error' => ['message' => 'The model `gpt-9` does not exist', 'type' => 'invalid_request_error']]);

        expect(classifyGenerate())->toBeInstanceOf(AiModelNotFoundException::class);
    });

    test('http 429 maps to a provider rate limit with an actionable message', function () {
        fakeClassifyError(429, ['error' => ['message' => 'slow down']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiRateLimitException::class)
            ->and($e->userMessage())->toBe('تم الوصول إلى الحد المسموح للطلبات. يرجى الانتظار ثم المحاولة مرة أخرى.');
    });

    test('http 429 with retry-after shows the remaining wait', function () {
        fakeClassifyError(429, ['error' => ['message' => 'slow down']], ['Retry-After' => '150']);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiRateLimitException::class)
            ->and($e->userMessage())->toContain('تم الوصول إلى الحد المسموح للطلبات. يرجى الانتظار ثم المحاولة مرة أخرى.')
            ->and($e->userMessage())->toContain('2');
    });

    test('http 429 with an empty-balance signal maps to insufficient balance instead', function () {
        fakeClassifyError(429, ['error' => ['message' => 'You exceeded your current quota', 'code' => 'insufficient_quota']]);

        expect(classifyGenerate())->toBeInstanceOf(AiInsufficientBalanceException::class);
    });

    test('http 5xx maps to provider unavailable with an actionable message', function () {
        fakeClassifyError(500, 'error');

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiUnavailableException::class)
            ->and($e->userMessage())->toBe('مزود الذكاء الاصطناعي غير متاح حاليًا. يرجى المحاولة لاحقًا.');
    });

    test('a connection failure maps to a timeout with an actionable message', function () {
        Http::fake(Http::failedConnection('cURL error 28: Operation timed out'));

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiTimeoutException::class)
            ->and($e->userMessage())->toBe('استغرق الاتصال بمزود الذكاء الاصطناعي وقتًا أطول من المتوقع. يرجى المحاولة مرة أخرى.');
    });

    test('http 200 with an invalid structure maps to an invalid response', function () {
        fakeClassifyError(200, ['choices' => []]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiInvalidResponseException::class)
            ->and($e->userMessage())->toBe('وصلت استجابة غير صالحة من مزود الذكاء الاصطناعي. يرجى المحاولة مرة أخرى.');
    });

    test('a context-length signal maps to the context limit message', function () {
        fakeClassifyError(400, ['error' => ['code' => 'context_length_exceeded', 'message' => 'This model maximum context length is 8192 tokens']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiContextLimitException::class)
            ->and($e->userMessage())->toBe('المحادثة طويلة جدًا ولا يمكن إرسالها إلى النموذج الحالي. حاول تقليل محتوى المحادثة أو بدء محادثة جديدة.');
    });

    test('a safety signal maps to the content-policy message', function () {
        fakeClassifyError(400, ['error' => ['message' => 'The response was blocked by content policy', 'code' => 'content_policy_violation']]);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiContentPolicyException::class)
            ->and($e->userMessage())->toBe('لم يتمكن مزود الذكاء الاصطناعي من معالجة هذا الطلب بسبب سياسات المحتوى الخاصة به.');
    });

    test('an unknown error falls back to the generic provider message', function () {
        fakeClassifyError(418, ['error' => 'teapot']);

        $e = classifyGenerate();

        expect($e)->toBeInstanceOf(AiProviderException::class)
            ->and($e->userMessage())->toBe('حدث خطأ أثناء الاتصال بمزود الذكاء الاصطناعي. يرجى المحاولة مرة أخرى.');
    });
});

describe('presenter and internals', function () {
    test('technical internals are preserved while messages stay safe', function () {
        fakeClassifyError(401, ['error' => ['message' => 'bad key', 'code' => 'invalid_api_key']], ['x-request-id' => 'req-123']);

        $e = classifyGenerate();

        expect($e->provider())->toBe('OpenAI')
            ->and($e->status())->toBe(401)
            ->and($e->requestId())->toBe('req-123')
            ->and($e->userMessage())->not()->toContain('bad key');
    });

    test('key-like secrets echoed by providers are redacted from diagnostics', function () {
        fakeClassifyError(401, ['error' => ['message' => 'Invalid API key sk-secret-xyz-1234567890 provided']]);

        $e = classifyGenerate();

        expect((string) $e->detail())->toContain('[redacted]')
            ->and((string) $e->detail())->not()->toContain('sk-secret')
            ->and($e->userMessage())->not()->toContain('sk-secret');
    });

    test('the presenter resolves every exception centrally', function () {
        expect(AiErrorPresenter::messageFor(new AiModelNotFoundException('openai')))
            ->toBe('النموذج المحدد غير متاح لدى مزود الذكاء الاصطناعي. تحقق من اسم النموذج وإعداداته.')
            ->and(AiErrorPresenter::messageFor(new AiInsufficientBalanceException('deepseek')))
            ->toBe('رصيد مزود الذكاء الاصطناعي غير كافٍ لتنفيذ الطلب.');
    });

    test('application usage limit stays distinct from provider rate limits', function () {
        $usage = new AiUsageLimitException('blocked', 60);
        $provider = new AiRateLimitException('openai', 429, 60);

        expect($usage)->not()->toBeInstanceOf(AiRateLimitException::class)
            ->and($provider)->not()->toBeInstanceOf(AiUsageLimitException::class)
            ->and($usage->userMessage())->toContain('حد الاستخدام المسموح')
            ->and($provider->userMessage())->toContain('الحد المسموح للطلبات')
            ->and($usage->userMessage())->not()->toBe($provider->userMessage());
    });
});

describe('streaming classification', function () {
    test('stream authentication failures classify like normal requests', function () {
        fakeClassifyError(401, ['error' => ['message' => 'bad key']]);

        expect(fn () => iterator_to_array(app(OpenAiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]), false))
            ->toThrow(AiAuthenticationException::class);
    });

    test('stream balance failures classify as insufficient balance', function () {
        fakeClassifyError(402, ['error' => ['message' => 'Insufficient Balance']]);

        expect(fn () => iterator_to_array(app(OpenAiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]), false))
            ->toThrow(AiInsufficientBalanceException::class);
    });
});

describe('retry behavior per category', function () {
    test('permanent errors are never retried', function (int $status, array $body) {
        config()->set('ai.retry.max_attempts', 3);
        fakeClassifyError($status, $body);

        try {
            app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'hi']]);
        } catch (AiException) {
        }

        Http::assertSentCount(1);
    })->with([
        'invalid request' => [400, ['error' => ['message' => 'Bad request']]],
        'authentication' => [401, ['error' => ['message' => 'bad key']]],
        'insufficient balance' => [402, ['error' => ['message' => 'Insufficient Balance']]],
        'authorization' => [403, ['error' => ['message' => 'forbidden']]],
        'model not found' => [404, ['error' => ['message' => 'no such model']]],
        'quota as balance on 429' => [429, ['error' => ['message' => 'insufficient quota', 'code' => 'insufficient_quota']]],
        'context limit' => [400, ['error' => ['code' => 'context_length_exceeded', 'message' => 'too long']]],
        'content policy' => [400, ['error' => ['code' => 'content_policy_violation', 'message' => 'blocked']]],
    ]);

    test('transient errors are still retried', function () {
        config()->set('ai.retry.max_attempts', 3);
        fakeClassifyError(429, ['error' => ['message' => 'slow down']]);

        try {
            app(OpenAiProvider::class)->generate([['role' => 'user', 'content' => 'hi']]);
        } catch (AiRateLimitException) {
        }

        Http::assertSentCount(3);
    });
});
