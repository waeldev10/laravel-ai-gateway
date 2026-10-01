<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function checkboxTags(): array
{
    $files = [
        resource_path('views/components/conversation/conversation-nav.blade.php'),
        resource_path('views/livewire/Conversation/conversation-search.blade.php'),
        resource_path('views/livewire/Auth/login-form.blade.php'),
    ];

    $tags = [];

    foreach ($files as $file) {
        $html = file_get_contents($file);
        preg_match_all('/<input\b(?:\{\{.*?\}\}|"[^"]*"|\'[^\']*\'|[^>])*>/s', $html, $matches);

        foreach ($matches[0] as $tag) {
            if (str_contains($tag, 'type="checkbox"')) {
                $tags[] = basename($file).': '.preg_replace('/\s+/', ' ', $tag);
            }
        }
    }

    return $tags;
}

describe('checkbox theme token', function () {
    test('checkboxes derive their checked color from the primary token only', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('accent-color: var(--color-primary)')
            ->and(substr_count($css, 'accent-color: var(--color-primary)'))->toBe(1)
            ->and($css)->not()->toMatch('/accent-color:\s*#[0-9a-fA-F]/');
    });

    test('every application checkbox carries the shared token class', function () {
        $tags = checkboxTags();

        // Conversation rows, the search Select All box, and login remember-me.
        expect($tags)->toHaveCount(3);

        foreach ($tags as $tag) {
            expect($tag)->toContain('ui-checkbox');
        }
    });

    test('checkbox focus stays visible through the same token system', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('.ui-checkbox:focus-visible')
            ->and($css)->toContain('outline: 2px solid var(--color-primary)');
    });

    test('unchecked borders keep their light and dark theme classes', function () {
        foreach (checkboxTags() as $tag) {
            expect($tag)->toContain('border-black/20')
                ->and($tag)->toContain('dark:border-white/20');
        }
    });
});

describe('checkbox behavior is unchanged', function () {
    test('selection checkboxes stay browser-only while remember-me keeps its binding', function () {
        foreach (checkboxTags() as $tag) {
            if (str_contains($tag, 'login-form')) {
                expect($tag)->toContain('wire:model="remember"');
            } else {
                expect($tag)->not()->toContain('wire:model');
            }
        }

        $search = file_get_contents(resource_path('views/livewire/Conversation/conversation-search.blade.php'));
        $nav = file_get_contents(resource_path('views/components/conversation/conversation-nav.blade.php'));

        expect($search)->toContain('allChecked')
            ->and($nav)->toContain('x-model="selected"');
    });

    test('the checkbox variable falls back to config defaults and stored colors apply client-side', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id]);
        $html = $this->actingAs($user)->get(route('conversations.search'))->assertOk()->getContent();

        $light = array_map(fn ($v) => strtoupper((string) $v), (array) config('ui.colors.defaults.light', []));

        // The server renders scheme defaults only; a saved browser palette
        // overrides the same variable client-side before first paint.
        expect($html)->toContain('--color-primary:'.$light['primary'])
            ->and($html)->toContain('window.__uiColors')
            ->and($html)->toContain('localStorage.getItem(KEY)')
            ->and($html)->toContain('ui-checkbox');
    });
});
