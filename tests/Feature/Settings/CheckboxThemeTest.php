<?php

use App\Livewire\UiColorSettings;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function checkboxTags(): array
{
    $files = [
        resource_path('views/components/conversation/conversation-nav.blade.php'),
        resource_path('views/conversations/index.blade.php'),
        resource_path('views/livewire/conversation-search.blade.php'),
        resource_path('views/livewire/login-form.blade.php'),
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

        // Conversation rows, both Select All boxes, and login remember-me.
        expect($tags)->toHaveCount(5);

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

        $search = file_get_contents(resource_path('views/livewire/conversation-search.blade.php'));
        $nav = file_get_contents(resource_path('views/components/conversation/conversation-nav.blade.php'));

        expect($search)->toContain('allChecked')
            ->and($nav)->toContain('x-model="selected"');
    });

    test('a persisted primary color reaches the variable checkboxes read', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(UiColorSettings::class)
            ->call('save', [
                'primary' => '#B91C1C',
                'primary_text' => '#FFFFFF',
                'accent' => '#B91C1C',
                'link' => '#B91C1C',
            ])
            ->assertDispatched('toast', type: 'success');

        // The same server-rendered variable drives .ui-checkbox after refresh.
        $html = $this->get(route('conversations.index'))->assertOk()->getContent();

        expect($html)->toContain('--color-primary:#B91C1C')
            ->and($html)->toContain('ui-checkbox');
    });
});
