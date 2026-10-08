<?php

use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Livewire\Prompts\PromptSettings;
use App\Models\AiMemory;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiContextService;
use App\Services\AI\AiPromptService;
use App\Services\Message\MessageService;
use Database\Seeders\AiDefaultPersonaSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
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

if (! function_exists('fakePromptChatSuccess')) {
    function fakePromptChatSuccess(string $pattern, string $text = 'رد المساعد'): void
    {
        Http::fake([
            $pattern => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            ], 200),
        ]);
    }
}

if (! function_exists('promptStreamEvents')) {
    function promptStreamEvents(string $content): array
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
}

describe('default prompt seeding', function () {
    test('the seeder creates exactly one ownerless flagged default', function () {
        $this->seed(AiDefaultPersonaSeeder::class);

        $defaults = AiPersona::query()->where('is_default', true)->get();

        expect($defaults)->toHaveCount(1);

        $default = $defaults->first();

        expect($default->user_id)->toBeNull()
            ->and($default->is_active)->toBeTrue()
            ->and($default->name)->toBe(AiDefaultPersonaSeeder::DEFAULT_NAME)
            ->and($default->system_prompt)->toBe(AiDefaultPersonaSeeder::DEFAULT_PROMPT);
    });

    test('re-running the seeder never duplicates or overwrites the default', function () {
        $this->seed(AiDefaultPersonaSeeder::class);

        AiPersona::query()->where('is_default', true)->update(['system_prompt' => 'Customized by admin.']);

        $this->seed(AiDefaultPersonaSeeder::class);

        expect(AiPersona::query()->where('is_default', true)->count())->toBe(1)
            ->and(AiPersona::query()->where('is_default', true)->value('system_prompt'))->toBe('Customized by admin.');
    });

    test('a legacy unflagged system row is promoted instead of duplicated', function () {
        AiPersona::factory()->create(['user_id' => null, 'system_prompt' => 'Legacy system prompt.']);

        $this->seed(AiDefaultPersonaSeeder::class);

        expect(AiPersona::query()->whereNull('user_id')->count())->toBe(1)
            ->and(AiPersona::query()->where('is_default', true)->value('system_prompt'))->toBe('Legacy system prompt.');
    });
});

describe('prompt service crud and selection', function () {
    test('a user can create, toggle, update, and delete a prompt', function () {
        $user = User::factory()->create();
        $service = app(AiPromptService::class);

        $first = $service->createFor($user, ['name' => 'First', 'system_prompt' => 'First prompt.']);
        $second = $service->createFor($user, ['name' => 'Second', 'system_prompt' => 'Second prompt.']);

        expect($first->user_id)->toBe((string) $user->getKey());

        // Toggling is independent: deactivating one never touches the other.
        $service->setActiveFor($user, $second, false);

        expect($service->activeCustomPersonas($user)->pluck('id')->all())
            ->toBe([(string) $first->getKey()]);

        $service->setActiveFor($user, $second, true);

        expect($service->activeCustomPersonas($user)->pluck('id')->sort()->values()->all())
            ->toBe(collect([(string) $first->getKey(), (string) $second->getKey()])->sort()->values()->all());

        $service->updateFor($user, $first, ['name' => 'Renamed', 'system_prompt' => 'Updated prompt.']);

        expect($first->refresh()->name)->toBe('Renamed');

        $service->deleteFor($user, $first);

        expect(AiPersona::query()->whereKey($first->getKey())->count())->toBe(0);
    });

    test('user input can never claim the system default flag or another owner', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $persona = app(AiPromptService::class)->createFor($user, [
            'name' => 'Mine',
            'system_prompt' => 'Mine.',
            'is_default' => true,
            'user_id' => $other->getKey(),
        ]);

        expect($persona->refresh()->is_default)->toBeFalse()
            ->and($persona->user_id)->toBe((string) $user->getKey());
    });

    test('a user cannot touch another user prompt', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = AiPersona::factory()->create(['user_id' => $other->id, 'system_prompt' => 'Theirs.']);
        $service = app(AiPromptService::class);

        expect(fn () => $service->setActiveFor($user, $foreign, true))->toThrow(AuthorizationException::class);
        expect(fn () => $service->updateFor($user, $foreign, ['name' => 'x', 'system_prompt' => 'y']))->toThrow(AuthorizationException::class);
        expect(fn () => $service->deleteFor($user, $foreign))->toThrow(AuthorizationException::class);

        expect($foreign->refresh()->system_prompt)->toBe('Theirs.');
    });

    test('the system default is read-only for normal users', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $system = AiPersona::query()->where('is_default', true)->firstOrFail();
        $service = app(AiPromptService::class);

        expect(fn () => $service->updateFor($user, $system, ['name' => 'x', 'system_prompt' => 'y']))->toThrow(AuthorizationException::class);
        expect(fn () => $service->deleteFor($user, $system))->toThrow(AuthorizationException::class);
        expect(fn () => $service->setActiveFor($user, $system, false))->toThrow(AuthorizationException::class);
        expect(fn () => $service->setActiveFor($user, $system, true))->toThrow(AuthorizationException::class);
    });

    test('resolution combines the flagged default with active customs in order', function () {
        $user = User::factory()->create();
        $service = app(AiPromptService::class);

        AiPersona::factory()->create(['user_id' => null, 'system_prompt' => 'Flagged default.', 'is_default' => true, 'priority' => 0]);
        AiPersona::factory()->create(['user_id' => null, 'system_prompt' => 'Loud system.', 'priority' => 100]);

        // Exactly one default resolves, even with other system rows present.
        expect($service->effectivePersonas($user)->pluck('system_prompt')->all())
            ->toBe(['Flagged default.']);

        AiPersona::factory()->create(['user_id' => $user->id, 'system_prompt' => 'User persona.']);

        // Default stays first, active customs follow.
        expect($service->effectivePersonas($user)->pluck('system_prompt')->all())
            ->toBe(['Flagged default.', 'User persona.']);
    });
});

describe('prompt and memory reach the provider', function () {
    test('the resolved prompt, memory, and bounded history form the normalized request', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        AiPersona::factory()->create(['user_id' => $user->id, 'system_prompt' => 'USER PERSONA.']);
        AiMemory::factory()->create(['user_id' => $user->id, 'content' => 'Prefers Arabic.']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => 'الأولى']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::Assistant, 'content' => 'أهلاً']);
        fakePromptChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الثانية');

        Http::assertSent(function ($request) {
            $messages = $request['messages'] ?? null;

            if (! is_array($messages) || count($messages) !== 5) {
                return false;
            }

            return $messages[0] === ['role' => 'system', 'content' => 'USER PERSONA.']
                && ($messages[1]['role'] ?? null) === 'system'
                && str_contains($messages[1]['content'] ?? '', 'Prefers Arabic.')
                && $messages[2] === ['role' => 'user', 'content' => 'الأولى']
                && $messages[3] === ['role' => 'assistant', 'content' => 'أهلاً']
                && $messages[4] === ['role' => 'user', 'content' => 'الثانية'];
        });
    });

    test('the seeded default prompt reaches the provider when the user has none', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakePromptChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'مرحبا');

        Http::assertSent(function ($request) {
            $messages = $request['messages'] ?? null;

            return is_array($messages)
                && ($messages[0] ?? null) === ['role' => 'system', 'content' => AiDefaultPersonaSeeder::DEFAULT_PROMPT]
                && end($messages) === ['role' => 'user', 'content' => 'مرحبا'];
        });
    });

    test('providers receive no users, ids, or records — only normalized messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        AiPersona::factory()->create(['user_id' => $user->id, 'system_prompt' => 'Persona.']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => 'hi']);
        fakePromptChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'again');

        Http::assertSent(function ($request) use ($user, $conversation) {
            $payload = json_encode($request->data());

            return ! str_contains($payload, (string) $user->getKey())
                && ! str_contains($payload, (string) $conversation->getKey());
        });
    });
});

describe('usage policy on streaming endpoints', function () {
    test('an exhausted budget yields a safe SSE error with no provider call', function () {
        config()->set('ai.usage.max_requests', 1);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakePromptChatSuccess('https://api.openai.com/*', 'مسموح');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'الأولى', AiSource::Web);

        $response = $this->actingAs($user)
            ->post(route('conversations.messages.stream', $conversation), ['content' => 'الثانية']);

        $response->assertOk();

        $events = promptStreamEvents($response->streamedContent());
        $error = collect($events)->firstWhere('type', 'error');

        expect($error)->not()->toBeNull()
            ->and($error['message'] ?? '')->not()->toBe('');

        Http::assertSentCount(1);
    });
});

describe('prompt settings ui', function () {
    test('guests are redirected to login', function () {
        $this->get(route('prompts.index'))->assertRedirect(route('login'));
    });

    test('the user menu links to prompt settings but exposes no memory ui', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('conversations.create'))->assertOk()->getContent();

        expect($html)->toContain(route('prompts.index'))
            ->and($html)->toContain('إعدادات البرومبت');

        expect(Route::has('memory.index'))->toBeFalse()
            ->and(Route::has('memories.index'))->toBeFalse();
    });

    test('the page shows the system default and the user own prompts only', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $other = User::factory()->create();
        AiPersona::factory()->create(['user_id' => $user->id, 'name' => 'Mine', 'system_prompt' => 'Mine.']);
        AiPersona::factory()->create(['user_id' => $other->id, 'name' => 'Theirs', 'system_prompt' => 'Theirs.']);

        $html = $this->actingAs($user)->get(route('prompts.index'))->assertOk()->getContent();

        expect($html)->toContain('البرومبت الافتراضي للنظام')
            ->and($html)->toContain('Mine')
            ->and($html)->not()->toContain('Theirs');
    });

    test('a user can create and activate a prompt through the component', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(PromptSettings::class)
            ->set('name', 'Senior Laravel Developer')
            ->set('prompt', 'You are a senior Laravel developer.')
            ->call('create')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        $persona = AiPersona::query()->where('user_id', $user->getKey())->firstOrFail();

        expect($persona->name)->toBe('Senior Laravel Developer');

        $texts = fn () => app(AiPromptService::class)->effectivePersonas($user)->pluck('system_prompt')->all();

        // New personas start active and resolve immediately.
        expect($texts())->toContain('You are a senior Laravel developer.');

        Livewire::test(PromptSettings::class)
            ->call('toggle', (string) $persona->getKey())
            ->assertDispatched('toast', type: 'success');

        expect($texts())->not()->toContain('You are a senior Laravel developer.');

        Livewire::test(PromptSettings::class)
            ->call('toggle', (string) $persona->getKey())
            ->assertDispatched('toast', type: 'success');

        expect($texts())->toContain('You are a senior Laravel developer.');
    });

    test('edit and two-step delete work through the component', function () {
        $user = User::factory()->create();
        $persona = AiPersona::factory()->create(['user_id' => $user->id, 'name' => 'Old', 'system_prompt' => 'Old prompt.']);
        $this->actingAs($user);

        Livewire::test(PromptSettings::class)
            ->call('startEdit', (string) $persona->getKey())
            ->set('editingName', 'New')
            ->set('editingPrompt', 'New prompt.')
            ->call('update')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        expect($persona->refresh()->name)->toBe('New');

        // First remove arms confirmation; nothing is deleted yet.
        Livewire::test(PromptSettings::class)
            ->call('remove', (string) $persona->getKey())
            ->assertSet('confirmingId', (string) $persona->getKey());

        expect(AiPersona::query()->whereKey($persona->getKey())->count())->toBe(1);

        // Second remove with the same id deletes.
        Livewire::test(PromptSettings::class)
            ->set('confirmingId', (string) $persona->getKey())
            ->call('remove', (string) $persona->getKey())
            ->assertDispatched('toast', type: 'success');

        expect(AiPersona::query()->whereKey($persona->getKey())->count())->toBe(0);
    });

    test('forged component ids cannot touch foreign prompts', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = AiPersona::factory()->create(['user_id' => $other->id, 'name' => 'Theirs', 'system_prompt' => 'Theirs.']);
        $this->actingAs($user);

        expect(fn () => Livewire::test(PromptSettings::class)->call('toggle', (string) $foreign->getKey()))
            ->toThrow(ModelNotFoundException::class);

        expect($foreign->refresh()->is_active)->toBeTrue();
    });
});

describe('context bounds without duplication', function () {
    test('the current message appears exactly once in the normalized request', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        fakePromptChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->createUserMessage($user, $conversation, 'رسالة واحدة');

        $built = app(AiContextService::class)->build($user, $conversation);
        $contents = array_column($built, 'content');

        expect(array_count_values($contents)['رسالة واحدة'] ?? 0)->toBe(1);
    });
});

describe('custom persona limits and multi-activation', function () {
    test('a user can create up to 3 custom personas, the 4th is rejected server-side', function () {
        $user = User::factory()->create();
        $service = app(AiPromptService::class);

        foreach (['One', 'Two', 'Three'] as $name) {
            $service->createFor($user, ['name' => $name, 'system_prompt' => "{$name} prompt."]);
        }

        expect($service->customCountFor($user))->toBe(3);

        expect(fn () => $service->createFor($user, ['name' => 'Four', 'system_prompt' => 'Fourth prompt.']))
            ->toThrow(InvalidArgumentException::class);

        expect($service->customCountFor($user))->toBe(3);
    });

    test('the system default never counts toward the custom limit', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $service = app(AiPromptService::class);

        foreach (['One', 'Two', 'Three'] as $name) {
            $service->createFor($user, ['name' => $name, 'system_prompt' => "{$name} prompt."]);
        }

        expect($service->customCountFor($user))->toBe(3)
            ->and($service->effectivePersonas($user))->toHaveCount(4);
    });

    test('any combination of custom personas may be active simultaneously', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $service = app(AiPromptService::class);
        $defaultText = AiDefaultPersonaSeeder::DEFAULT_PROMPT;

        $customs = [];

        foreach (['One', 'Two', 'Three'] as $name) {
            $customs[] = $service->createFor($user, ['name' => $name, 'system_prompt' => "{$name} prompt."]);
            $service->setActiveFor($user, $customs[count($customs) - 1], false);
        }

        $texts = fn () => $service->effectivePersonas($user)->pluck('system_prompt')->all();

        // Case 1: none active — default alone.
        expect($texts())->toBe([$defaultText]);

        // Case 2: one active.
        $service->setActiveFor($user, $customs[0], true);

        expect($texts())->toBe([$defaultText, 'One prompt.']);

        // Case 3: two active.
        $service->setActiveFor($user, $customs[1], true);

        expect($texts())->toBe([$defaultText, 'One prompt.', 'Two prompt.']);

        // Case 4: all three active.
        $service->setActiveFor($user, $customs[2], true);

        expect($texts())->toBe([$defaultText, 'One prompt.', 'Two prompt.', 'Three prompt.']);

        // Deactivating one leaves the rest untouched.
        $service->setActiveFor($user, $customs[0], false);

        expect($texts())->toBe([$defaultText, 'Two prompt.', 'Three prompt.']);
    });

    test('the default can never be deactivated or selected', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $service = app(AiPromptService::class);
        $system = AiPersona::query()->where('is_default', true)->firstOrFail();

        expect(fn () => $service->setActiveFor($user, $system, false))->toThrow(AuthorizationException::class);

        expect($system->refresh()->is_active)->toBeTrue()
            ->and($service->effectivePersonas($user)->pluck('system_prompt')->all())
            ->toBe([AiDefaultPersonaSeeder::DEFAULT_PROMPT]);
    });

    test('the effective prompt reaches the provider with default plus actives in order', function () {
        $this->seed(AiDefaultPersonaSeeder::class);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $service = app(AiPromptService::class);

        $alpha = $service->createFor($user, ['name' => 'Alpha', 'system_prompt' => 'Alpha prompt.', 'priority' => 5]);
        $service->createFor($user, ['name' => 'Beta', 'system_prompt' => 'Beta prompt.', 'priority' => 1]);
        $gamma = $service->createFor($user, ['name' => 'Gamma', 'system_prompt' => 'Gamma prompt.']);
        $service->setActiveFor($user, $gamma, false);
        fakePromptChatSuccess('https://api.openai.com/*', 'رد');

        app(MessageService::class)->sendUserMessage($user, $conversation, 'مرحبا');

        $defaultText = AiDefaultPersonaSeeder::DEFAULT_PROMPT;

        Http::assertSent(function ($request) use ($defaultText) {
            $messages = $request['messages'] ?? null;

            if (! is_array($messages) || count($messages) < 4) {
                return false;
            }

            return $messages[0] === ['role' => 'system', 'content' => $defaultText]
                && $messages[1] === ['role' => 'system', 'content' => 'Alpha prompt.']
                && $messages[2] === ['role' => 'system', 'content' => 'Beta prompt.']
                && end($messages) === ['role' => 'user', 'content' => 'مرحبا'];
        });
    });

    test('limit and toggles are reflected through the component', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['One', 'Two'] as $name) {
            Livewire::test(PromptSettings::class)
                ->set('name', $name)
                ->set('prompt', "{$name} prompt.")
                ->call('create')
                ->assertHasNoErrors();
        }

        // Below the limit the creation form is available.
        $html = $this->get(route('prompts.index'))->assertOk()->getContent();

        expect($html)->toContain('إنشاء البرومبت')
            ->and($html)->not()->toContain('الحد الأقصى');

        Livewire::test(PromptSettings::class)
            ->set('name', 'Three')
            ->set('prompt', 'Three prompt.')
            ->call('create')
            ->assertHasNoErrors();

        // At the limit the form hides behind an explanatory notice.
        $html = $this->get(route('prompts.index'))->assertOk()->getContent();

        expect($html)->toContain('الحد الأقصى')
            ->and($html)->not()->toContain('إنشاء البرومبت');

        // A fourth creation is rejected server-side: count never moves.
        Livewire::test(PromptSettings::class)
            ->set('name', 'Four')
            ->set('prompt', 'Fourth prompt.')
            ->call('create')
            ->assertDispatched('toast', type: 'error');

        expect(AiPersona::query()->where('user_id', $user->getKey())->count())->toBe(3);

        // Active rows offer deactivation, inactive rows offer activation.
        $active = AiPersona::query()->where('user_id', $user->getKey())->firstOrFail();
        app(AiPromptService::class)->setActiveFor($user, $active, false);

        $html = $this->get(route('prompts.index'))->assertOk()->getContent();

        expect($html)->toContain('تفعيل')
            ->and($html)->toContain('إلغاء التفعيل');
    });
});
