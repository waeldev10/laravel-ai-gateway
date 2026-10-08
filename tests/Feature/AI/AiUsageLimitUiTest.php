<?php

use App\AI\Exceptions\AiUsageLimitException;
use App\Enums\MessageRole;
use App\Livewire\Chat\MessageComposer;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AI\AiUsageService;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

    config()->set('ai.providers.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.protocol', 'chat_completions');
    config()->set('ai.providers.openai.timeout', 30);

    Http::preventStrayRequests();
});

function fakeLimitChatSuccess(string $pattern, string $text = 'رد المساعد'): void
{
    Http::fake([
        $pattern => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
        ], 200),
    ]);
}

function limitStreamEvents(string $content): array
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

/**
 * Exhaust a max_requests=1 budget so the user is under an active
 * application cooldown. Returns the conversation used.
 */
function activateLimitCooldown(User $user): Conversation
{
    config()->set('ai.usage.max_requests', 1);

    $conversation = Conversation::factory()->create(['user_id' => $user->id]);
    fakeLimitChatSuccess('https://api.openai.com/*', 'مسموح');

    app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى');

    try {
        app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية');

        test()->fail('Expected an AiUsageLimitException.');
    } catch (AiUsageLimitException $e) {
    }

    return $conversation;
}

function limitTextareaTag(string $html): string
{
    preg_match('/<textarea\b[^>]*data-composer-input[^>]*>/', $html, $matches);

    return $matches[0] ?? '';
}

/**
 * The disabled *attribute* (not the `disabled:` Tailwind variant class
 * the textarea always carries for its disabled styling).
 */
function limitTextareaDisabled(string $html): bool
{
    return (bool) preg_match('/\sdisabled(?=[\s>])/', limitTextareaTag($html));
}

describe('usage limit banner', function () {
    test('an active cooldown renders the limit message above a disabled composer', function () {
        $user = User::factory()->create();
        $conversation = activateLimitCooldown($user);

        $html = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();

        expect($html)->toContain('لقد وصلت إلى الحد المسموح من طلبات الذكاء الاصطناعي. يرجى الانتظار حتى انتهاء فترة التهدئة قبل إرسال طلب جديد.')
            ->and($html)->toContain('data-usage-limit-banner')
            ->and($html)->toContain('data-usage-limited="1"');

        // Banner sits above the composer form inside the conversation screen.
        expect(strpos($html, 'data-usage-limit-banner'))->toBeLessThan(strpos($html, 'data-composer-form'));

        // The textarea itself is disabled server-side.
        expect(limitTextareaDisabled($html))->toBeTrue();
    });

    test('the remaining cooldown is displayed with its countdown state', function () {
        $user = User::factory()->create();
        $conversation = activateLimitCooldown($user);

        $html = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();

        expect($html)->toContain('يمكنك إرسال طلب جديد بعد')
            ->and($html)->toContain('data-usage-countdown-text')
            ->and($html)->toMatch('/data-retry-after="\d+"/');

        $remaining = app(AiUsageService::class)->remainingCooldown($user);

        expect($remaining)->not()->toBeNull()->and($remaining)->toBeGreaterThan(0);
    });

    test('the new-chat page also renders the banner while limited', function () {
        $user = User::factory()->create();
        activateLimitCooldown($user);

        $html = $this->actingAs($user)->get(route('conversations.create'))->assertOk()->getContent();

        expect($html)->toContain('data-usage-limit-banner')
            ->and(limitTextareaDisabled($html))->toBeTrue();
    });
});

describe('normal composer state', function () {
    test('users without an active limit see the normal enabled composer', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $html = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();

        expect($html)->not()->toContain('data-usage-limit-banner')
            ->and($html)->not()->toContain('لقد وصلت إلى الحد المسموح')
            ->and($html)->toContain('data-usage-limited="0"');

        expect(limitTextareaDisabled($html))->toBeFalse();
    });
});

describe('usage limit error protocol', function () {
    test('a blocked stream carries the usage code, never a generic error', function () {
        $user = User::factory()->create();
        $conversation = activateLimitCooldown($user);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'محاولة محظورة']);

        $response->assertOk();

        $error = collect(limitStreamEvents($response->streamedContent()))->firstWhere('type', 'error');

        expect($error)->not()->toBeNull()
            ->and($error['code'] ?? null)->toBe('usage_limit')
            ->and($error['retry_after'] ?? null)->toBeGreaterThan(0)
            ->and($error['message'] ?? '')->toContain('حد الاستخدام المسموح')
            ->and($error['message'] ?? '')->not()->toContain('غير متوقع');

        // The blocked attempt never reached any provider.
        Http::assertSentCount(1);
    });

    test('a provider 429 never carries the application usage code', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'slow down'], 429)]);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'hi']);

        $error = collect(limitStreamEvents($response->streamedContent()))->firstWhere('type', 'error');

        expect($error)->not()->toBeNull()
            ->and($error)->not()->toHaveKey('code');

        // And no application cooldown was activated by the upstream 429.
        expect(app(AiUsageService::class)->remainingCooldown($user))->toBeNull();

        $html = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();

        expect($html)->not()->toContain('data-usage-limit-banner');
    });
});

describe('usage status endpoint', function () {
    test('it reflects the authoritative backend state', function () {
        $user = User::factory()->create();
        activateLimitCooldown($user);

        $this->actingAs($user)->getJson(route('ai.usage-status'))
            ->assertOk()
            ->assertJson(['limited' => true])
            ->assertJsonPath('retry_after', fn ($value) => is_int($value) && $value > 0);

        $this->travel(16)->minutes();

        $this->actingAs($user)->getJson(route('ai.usage-status'))
            ->assertOk()
            ->assertJson(['limited' => false, 'retry_after' => null]);
    });

    test('guests are redirected to login', function () {
        $this->getJson(route('ai.usage-status'))->assertUnauthorized();
    });
});

describe('legacy composer send under cooldown', function () {
    test('it refreshes the banner state instead of a generic toast', function () {
        $user = User::factory()->create();
        $conversation = activateLimitCooldown($user);
        $this->actingAs($user);

        Livewire::test(MessageComposer::class, ['conversation' => $conversation])
            ->set('content', 'محاولة محظورة')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('usageLimited', true)
            ->assertNotDispatched('toast');

        // Same guarantee as other failures: the user message persists.
        expect($conversation->messages()->where('role', MessageRole::User)->where('content', 'محاولة محظورة')->count())->toBe(1);
    });
});
