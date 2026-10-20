<?php

use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Protocols\InteractionsProtocol;
use App\AI\Providers\Gemini\GeminiProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.provider', 'gemini');
    config()->set('ai.retry.max_attempts', 1);
    config()->set('ai.retry.base_sleep_ms', 0);
    config()->set('ai.retry.max_sleep_ms', 0);
    config()->set('ai.providers.gemini.api_key', 'test-gemini-key');
    config()->set('ai.providers.gemini.model', 'gemini-2.0-flash');
    config()->set('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    config()->set('ai.providers.gemini.timeout', 30);
    config()->set('ai.providers.gemini.protocol', 'interactions');

    Http::preventStrayRequests();
});

function fakeInteractionsSuccess(string $pattern, array $body): void
{
    Http::fake([$pattern => Http::response($body, 200)]);
}

function fakeInteractionsStream(string $pattern, string $sse): void
{
    Http::fake([$pattern => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
}

function interactionsDeltaSse(array $chunks, string $prefix = '', string $suffix = ''): string
{
    $sse = "event: interaction.created\n";
    $sse .= 'data: '.json_encode([
        'interaction' => ['id' => 'v1_test', 'status' => 'in_progress'],
        'event_type' => 'interaction.created',
    ])."\n\n";
    $sse .= $prefix;

    foreach ($chunks as $chunk) {
        $sse .= "event: step.delta\n";
        $sse .= 'data: '.json_encode([
            'event_type' => 'step.delta',
            'delta' => ['type' => 'text', 'text' => $chunk],
        ])."\n\n";
    }

    $sse .= $suffix;
    $sse .= "event: interaction.completed\n";
    $sse .= 'data: '.json_encode([
        'interaction' => ['id' => 'v1_test', 'status' => 'completed'],
        'event_type' => 'interaction.completed',
    ])."\n\n";
    $sse .= "event: done\n";
    $sse .= "data: [DONE]\n\n";

    return $sse;
}

describe('interactions endpoint and headers', function () {
    test('the endpoint uses the configured base url', function () {
        $protocol = app(InteractionsProtocol::class);

        expect($protocol->endpoint([
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'model' => 'm',
        ]))->toBe('https://generativelanguage.googleapis.com/v1beta/interactions')
            ->and($protocol->endpoint([
                'base_url' => 'https://example.test/v1beta/',
                'model' => 'm',
            ]))->toBe('https://example.test/v1beta/interactions');
    });

    test('authentication uses the api key header', function () {
        $protocol = app(InteractionsProtocol::class);

        expect($protocol->headers(['api_key' => 'secret-key']))->toBe(['x-goog-api-key' => 'secret-key']);
    });

    test('the stream endpoint is the same resource without a query flag', function () {
        $protocol = app(InteractionsProtocol::class);
        $config = ['base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'm'];

        expect($protocol->streamEndpoint($config))->toBe($protocol->endpoint($config))
            ->and($protocol->streamEndpoint($config))->not()->toContain('alt=sse');
    });
});

describe('interactions payload', function () {
    test('a single user message produces the exact documented request body', function () {
        $payload = app(InteractionsProtocol::class)->payload(
            [['role' => 'user', 'content' => 'مرحبا']],
            ['model' => 'gemini-2.0-flash']
        );

        expect($payload)->toBe([
            'model' => 'gemini-2.0-flash',
            'input' => [['role' => 'user', 'content' => 'مرحبا']],
        ]);
    });

    test('the model role passes through as model', function () {
        $payload = app(InteractionsProtocol::class)->payload([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'model', 'content' => 'hello'],
        ], ['model' => 'm']);

        expect($payload['input'])->toBe([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'model', 'content' => 'hello'],
        ]);
    });

    test('missing content defaults to an empty string', function () {
        $payload = app(InteractionsProtocol::class)->payload(
            [['role' => 'user']],
            ['model' => 'm']
        );

        expect($payload['input'])->toBe([['role' => 'user', 'content' => '']]);
    });

    test('message order is preserved with assistant mapped to model', function () {
        $payload = app(InteractionsProtocol::class)->payload([
            ['role' => 'user', 'content' => 'مرحبا'],
            ['role' => 'assistant', 'content' => 'أهلاً'],
            ['role' => 'user', 'content' => 'وكيف حالك؟'],
        ], ['model' => 'gemini-2.0-flash']);

        expect($payload['model'])->toBe('gemini-2.0-flash')
            ->and($payload['input'])->toBe([
                ['role' => 'user', 'content' => 'مرحبا'],
                ['role' => 'model', 'content' => 'أهلاً'],
                ['role' => 'user', 'content' => 'وكيف حالك؟'],
            ])
            ->and($payload)->not()->toHaveKey('system_instruction');
    });

    test('system messages become the native system instruction', function () {
        $payload = app(InteractionsProtocol::class)->payload([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'system', 'content' => 'Answer in Arabic.'],
            ['role' => 'user', 'content' => 'مرحبا'],
        ], ['model' => 'm']);

        expect($payload['input'])->toBe([['role' => 'user', 'content' => 'مرحبا']])
            ->and($payload['system_instruction'])->toBe("You are helpful.\n\nAnswer in Arabic.");
    });

    test('the stream payload adds the documented stream flag', function () {
        $protocol = app(InteractionsProtocol::class);
        $payload = $protocol->streamPayload([['role' => 'user', 'content' => 'hi']], ['model' => 'm']);

        expect($payload['stream'])->toBeTrue()
            ->and($payload['model'])->toBe('m')
            ->and($payload['input'])->toBe([['role' => 'user', 'content' => 'hi']]);
    });

    test('an unsupported role fails explicitly instead of degrading to user', function () {
        expect(fn () => app(InteractionsProtocol::class)->payload([
            ['role' => 'tool', 'content' => 'x'],
        ], ['model' => 'm']))->toThrow(InvalidArgumentException::class, 'Unsupported message role');
    });
});

describe('interactions full responses', function () {
    test('a successful response returns the model output text', function () {
        fakeInteractionsSuccess('https://generativelanguage.googleapis.com/*', [
            'id' => 'v1_test',
            'status' => 'completed',
            'steps' => [
                ['type' => 'thought', 'signature' => 'abc'],
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'أهلاً بك']]],
            ],
        ]);

        $text = app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('أهلاً بك');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
                && $request->toPsrRequest()->getHeaderLine('x-goog-api-key') === 'test-gemini-key'
                && $request['model'] === 'gemini-2.0-flash'
                && $request['input'] === [['role' => 'user', 'content' => 'مرحبا']];
        });
    });

    test('text blocks within the final output step are joined', function () {
        fakeInteractionsSuccess('https://generativelanguage.googleapis.com/*', [
            'id' => 'v1_test',
            'status' => 'completed',
            'steps' => [
                ['type' => 'thought', 'signature' => 'abc'],
                ['type' => 'model_output', 'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'image', 'mime_type' => 'image/png', 'data' => '...'],
                    ['type' => 'text', 'text' => ' world'],
                ]],
            ],
        ]);

        expect(app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'hi']]))->toBe('Hello world');
    });

    test('the final model output wins over intermediate output', function () {
        fakeInteractionsSuccess('https://generativelanguage.googleapis.com/*', [
            'id' => 'v1_test',
            'status' => 'completed',
            'steps' => [
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'draft']]],
                ['type' => 'thought', 'signature' => 'abc'],
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'final']]],
            ],
        ]);

        expect(app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'hi']]))->toBe('final');
    });

    test('missing or malformed content fails clearly', function () {
        foreach ([
            'no steps key' => ['id' => 'v1_test', 'status' => 'completed'],
            'empty steps' => ['steps' => []],
            'no model output' => ['steps' => [['type' => 'thought', 'signature' => 'x']]],
            'empty text' => ['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '']]]]],
            'non array body' => 'not-an-object',
        ] as $name => $body) {
            fakeInteractionsSuccess('https://generativelanguage.googleapis.com/*',
                is_array($body) ? $body : ['unexpected' => $body]
            );

            expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'hi']]))
                ->toThrow(AiInvalidResponseException::class);
        }
    });
});

describe('interactions streaming events', function () {
    test('text deltas are extracted and anything else is ignored', function () {
        $protocol = app(InteractionsProtocol::class);

        expect($protocol->extractDelta(
            'data: {"event_type":"step.delta","delta":{"type":"text","text":"مرحبا"}}'."\n"
        ))->toBe('مرحبا')
            ->and($protocol->extractDelta(
                'data: {"event_type":"step.delta","delta":{"type":"image","data":"..."}}'."\n"
            ))->toBeNull()
            ->and($protocol->extractDelta(
                'data: {"event_type":"interaction.status_update","status":"in_progress"}'."\n"
            ))->toBeNull()
            ->and($protocol->extractDelta(
                'data: {"event_type":"interaction.completed","interaction":{"status":"completed"}}'."\n"
            ))->toBeNull()
            ->and($protocol->extractDelta("event: step.delta\n"))->toBeNull()
            ->and($protocol->extractDelta(": heartbeat\n"))->toBeNull()
            ->and($protocol->extractDelta("\n"))->toBeNull()
            ->and($protocol->extractDelta("data: [DONE]\n"))->toBeNull()
            ->and($protocol->extractDelta("data: not-json{{{\n"))->toBeNull()
            ->and($protocol->extractDelta('data: {"event_type":"step.delta"}'."\n"))->toBeNull();
    });

    test('a line without a trailing newline still yields its delta', function () {
        expect(app(InteractionsProtocol::class)->extractDelta(
            'data: {"event_type":"step.delta","delta":{"type":"text","text":"tail"}}'
        ))->toBe('tail');
    });

    test('completion events are recognized', function () {
        $protocol = app(InteractionsProtocol::class);

        expect($protocol->isStreamDone("event: interaction.completed\n"))->toBeTrue()
            ->and($protocol->isStreamDone("event: done\n"))->toBeTrue()
            ->and($protocol->isStreamDone("data: [DONE]\n"))->toBeTrue()
            ->and($protocol->isStreamDone(
                'data: {"event_type":"interaction.completed","interaction":{"status":"completed"}}'."\n"
            ))->toBeTrue()
            ->and($protocol->isStreamDone(
                'data: {"event_type":"step.delta","delta":{"type":"text","text":"x"}}'."\n"
            ))->toBeFalse()
            ->and($protocol->isStreamDone("event: step.delta\n"))->toBeFalse()
            ->and($protocol->isStreamDone("data: not-json{{{\n"))->toBeFalse()
            ->and($protocol->isStreamDone("\n"))->toBeFalse();
    });

    test('error events are never treated as completion or text', function () {
        $protocol = app(InteractionsProtocol::class);
        $error = 'data: {"event_type":"error","error":{"code":"gateway_timeout","message":"Deadline expired."}}'."\n";

        expect($protocol->isStreamDone($error))->toBeFalse()
            ->and($protocol->isStreamDone("event: error\n"))->toBeFalse();
    });

    test('an error event fails the stream instead of yielding text', function () {
        expect(fn () => app(InteractionsProtocol::class)->extractDelta(
            'data: {"event_type":"error","error":{"code":"gateway_timeout","message":"Deadline expired."}}'."\n"
        ))->toThrow(AiInvalidResponseException::class);
    });

    test('an error after deltas still fails instead of returning partial text', function () {
        $sse = interactionsDeltaSse(
            ['Hello', ' world'],
            '',
            "event: error\n"
            .'data: {"event_type":"error","error":{"code":"internal","message":"Boom."}}'."\n\n"
        );

        fakeInteractionsStream('https://generativelanguage.googleapis.com/*', $sse);

        expect(fn () => iterator_to_array(
            app(GeminiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]),
            false
        ))->toThrow(AiInvalidResponseException::class);
    });

    test('an error-only stream fails as a provider error', function () {
        fakeInteractionsStream(
            'https://generativelanguage.googleapis.com/*',
            "event: error\n"
            .'data: {"event_type":"error","error":{"code":"not_found","message":"Gone."}}'."\n\n"
        );

        expect(fn () => iterator_to_array(
            app(GeminiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]),
            false
        ))->toThrow(AiInvalidResponseException::class);
    });

    test('deltas stream end to end with non-text events ignored', function () {
        $sse = interactionsDeltaSse(
            ['Hello', ' world'],
            "event: interaction.status_update\n"
            .'data: {"event_type":"interaction.status_update","status":"in_progress"}'."\n\n"
            ."event: step.delta\n"
            .'data: {"event_type":"step.delta","delta":{"type":"thinking","text":"hidden"}}'."\n\n"
            ."data: not-json{{{\n\n"
        );

        fakeInteractionsStream('https://generativelanguage.googleapis.com/*', $sse);

        $deltas = iterator_to_array(
            app(GeminiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]),
            false
        );

        expect($deltas)->toBe(['Hello', ' world']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
                && $request['stream'] === true
                && $request['model'] === 'gemini-2.0-flash';
        });
    });

    test('a delta longer than the transport read size survives chunk splits', function () {
        $long = str_repeat('ن', 3000);

        fakeInteractionsStream(
            'https://generativelanguage.googleapis.com/*',
            interactionsDeltaSse([$long])
        );

        $deltas = iterator_to_array(
            app(GeminiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]),
            false
        );

        expect($deltas)->toBe([$long]);
    });

    test('a stream with no deltas and no completion fails clearly', function () {
        fakeInteractionsStream(
            'https://generativelanguage.googleapis.com/*',
            ": heartbeat\n\n: another\n\n"
        );

        expect(fn () => iterator_to_array(
            app(GeminiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]),
            false
        ))->toThrow(AiInvalidResponseException::class);
    });

    test('a completion-only stream ends normally with no deltas', function () {
        fakeInteractionsStream(
            'https://generativelanguage.googleapis.com/*',
            "event: interaction.completed\n"
            .'data: {"event_type":"interaction.completed"}'."\n\n"
            ."event: done\n data: [DONE]\n\n"
        );

        $deltas = iterator_to_array(
            app(GeminiProvider::class)->stream([['role' => 'user', 'content' => 'hi']]),
            false
        );

        expect($deltas)->toBe([]);
    });
});

describe('gemini protocol selection', function () {
    test('the default protocol remains generate content', function () {
        config()->set('ai.providers.gemini.protocol', null);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'قديم']]]]],
            ], 200),
        ]);

        $text = app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('قديم');

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            ':generateContent'
        ));
    });

    test('selecting interactions invokes the new protocol', function () {
        config()->set('ai.providers.gemini.protocol', 'interactions');
        fakeInteractionsSuccess('https://generativelanguage.googleapis.com/*', [
            'id' => 'v1_test',
            'status' => 'completed',
            'steps' => [
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'جديد']]],
            ],
        ]);

        $text = app(GeminiProvider::class)->generate([
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'مرحبا'],
            ['role' => 'assistant', 'content' => 'أهلاً'],
            ['role' => 'user', 'content' => 'أكمل'],
        ]);

        expect($text)->toBe('جديد');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
                && $request['system_instruction'] === 'Be concise.'
                && $request['input'] === [
                    ['role' => 'user', 'content' => 'مرحبا'],
                    ['role' => 'model', 'content' => 'أهلاً'],
                    ['role' => 'user', 'content' => 'أكمل'],
                ];
        });
    });

    test('selecting generate content keeps the existing protocol', function () {
        config()->set('ai.providers.gemini.protocol', 'generate_content');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'موجود']]]]],
            ], 200),
        ]);

        $text = app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]);

        expect($text)->toBe('موجود');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), ':generateContent')
                && $request['contents'] === [['role' => 'user', 'parts' => [['text' => 'مرحبا']]]];
        });
    });

    test('an unsupported protocol still fails without an http request', function () {
        config()->set('ai.providers.gemini.protocol', 'responses');

        expect(fn () => app(GeminiProvider::class)->generate([['role' => 'user', 'content' => 'مرحبا']]))
            ->toThrow(AiProviderException::class, 'does not support');

        Http::assertNothingSent();
    });
});
