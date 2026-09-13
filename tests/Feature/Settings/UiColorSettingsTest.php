<?php

use App\Livewire\UiColorSettings;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Theme\UiColorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function validPalette(): array
{
    return [
        'primary' => '#2563EB',
        'primary_text' => '#FFFFFF',
        'accent' => '#7C3AED',
        'link' => '#1D4ED8',
    ];
}

describe('settings user menu', function () {
    test('menu contains profile, settings and logout in order', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('الملف الشخصي')
            ->and($html)->toContain('الإعدادات')
            ->and($html)->toContain('تسجيل الخروج')
            ->and(strpos($html, 'الملف الشخصي') < strpos($html, 'الإعدادات'))->toBeTrue()
            ->and(strpos($html, 'الإعدادات') < strpos($html, 'تسجيل الخروج'))->toBeTrue();
    });

    test('opening the settings modal costs no server request', function () {
        $menu = file_get_contents(resource_path('views/components/sidebar/sidebar-user-menu.blade.php'));

        // Browser-only interaction: a button dispatching an Alpine event, never a link or Livewire call.
        expect($menu)->toContain("CustomEvent('open-settings-modal')")
            ->and($menu)->not()->toContain('wire:click');

        $modal = file_get_contents(resource_path('views/livewire/ui-color-settings.blade.php'));

        expect($modal)->toContain('@open-settings-modal.window')
            ->and($modal)->toContain('@close-settings-modal.window')
            ->and($modal)->toContain('@keydown.escape.window');
    });
});

describe('settings modal', function () {
    test('modal is reusable, accessible, responsive and theme-aware', function () {
        $source = file_get_contents(resource_path('views/livewire/ui-color-settings.blade.php'));

        expect($source)->toContain('role="dialog"')
            ->and($source)->toContain('aria-modal="true"')
            ->and($source)->toContain('aria-labelledby="settings-title"')
            ->and($source)->toContain('fixed inset-0 z-[60]')
            ->and($source)->toContain('max-w-md')
            ->and($source)->toContain('dark:bg-zinc-900')
            ->and($source)->toContain('aria-label="إغلاق الإعدادات"');
    });

    test('color picking previews without a server request', function () {
        $source = file_get_contents(resource_path('views/livewire/ui-color-settings.blade.php'));

        // Native color controls bound to Alpine draft only; preview mutates CSS vars locally.
        expect(substr_count($source, 'type="color"'))->toBe(4)
            ->and($source)->toContain('x-model="draft.primary"')
            ->and($source)->toContain('x-model="draft.primary_text"')
            ->and($source)->toContain('x-model="draft.accent"')
            ->and($source)->toContain('x-model="draft.link"')
            ->and($source)->toContain('@input="preview()"')
            ->and($source)->toContain("setProperty('--color-primary'")
            ->and($source)->not()->toContain('wire:model');
    });

    test('save button has a real loading state and prevents duplicates', function () {
        $source = file_get_contents(resource_path('views/livewire/ui-color-settings.blade.php'));

        expect($source)->toContain('wire:loading.attr="disabled"')
            ->and($source)->toContain('wire:target="save')
            ->and($source)->toContain(':disabled="busy"')
            ->and($source)->toContain('جارٍ الحفظ...')
            ->and($source)->toContain('استعادة الألوان الافتراضية')
            ->and($source)->not()->toContain('setTimeout');
    });
});

describe('settings persistence', function () {
    test('saving valid colors persists them and dispatches the unified toast without reloading', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(UiColorSettings::class)
            ->call('save', validPalette())
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('ui-colors-saved')
            ->assertDispatched('close-settings-modal');

        expect($component->effects['redirect'] ?? null)->toBeNull();

        $stored = $user->refresh()->ui_colors;

        expect($stored['primary'])->toBe('#2563EB')
            ->and($stored['primary_text'])->toBe('#FFFFFF')
            ->and($stored['accent'])->toBe('#7C3AED')
            ->and($stored['link'])->toBe('#1D4ED8');
    });

    test('invalid color values are rejected and persist nothing', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach ([
            ['primary' => 'red', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '#2563EB', 'primary_text' => 'url(evil)', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '#FFF', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '<script>', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '#2563EB;expression(x)', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
        ] as $bad) {
            Livewire::test(UiColorSettings::class)
                ->call('save', $bad)
                ->assertDispatched('toast', type: 'error');
        }

        expect($user->refresh()->ui_colors)->toBeNull();
    });

    test('saved values survive refresh and drive the rendered CSS variables', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(UiColorSettings::class)->call('save', validPalette());

        foreach ([route('conversations.index'), route('conversations.create'), route('profile.show')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            expect($html)->toContain('<style id="ui-colors">')
                ->and($html)->toContain('#2563EB')
                ->and($html)->toContain('#FFFFFF')
                ->and($html)->toContain('#7C3AED')
                ->and($html)->toContain('#1D4ED8');
        }
    });

    test('reset restores the authoritative defaults and toasts success', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(UiColorSettings::class)->call('save', validPalette());
        expect($user->refresh()->ui_colors)->not()->toBeNull();

        Livewire::test(UiColorSettings::class)
            ->call('resetToDefaults')
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('ui-colors-saved')
            ->assertDispatched('close-settings-modal');

        expect($user->refresh()->ui_colors)->toBeNull();

        $defaults = app(UiColorService::class)->defaults()['light'];
        $html = $this->get(route('conversations.index'))->assertOk()->getContent();

        expect($html)->toContain($defaults['primary'])
            ->and($html)->not()->toContain('#2563EB');
    });

    test('service is the single source for defaults and sanitizes output', function () {
        $service = app(UiColorService::class);

        expect($service->tokens())->toBe(['primary', 'primary_text', 'accent', 'link']);

        $source = file_get_contents(resource_path('views/components/theme/ui-colors.blade.php'));

        // One style tag, values only from the service (no hard-coded second copy in Blade).
        expect(substr_count($source, '<style id="ui-colors">'))->toBe(1)
            ->and($source)->toContain('effectiveFor')
            ->and($source)->not()->toContain('#000000')
            ->and($source)->not()->toContain('#ffffff');

        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('.ui-primary-btn')
            ->and($css)->toContain('.ui-primary-surface')
            ->and($css)->toContain('.ui-link')
            ->and($css)->toContain('.ui-accent-soft')
            ->and($css)->toContain('var(--color-primary)')
            ->and($css)->toContain('var(--color-link)');
    });
});

describe('settings theme compatibility', function () {
    test('tokens render for both schemes and reuse the existing theme state', function () {
        // Links use the link token on auth pages (guest first, before actingAs).
        $guestHtml = $this->get(route('login'))->assertOk()->getContent();
        expect($guestHtml)->toContain('ui-link')
            ->and($guestHtml)->toContain('<style id="ui-colors">');

        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('conversations.index'))->assertOk()->getContent();

        expect($html)->toContain(':root{--color-primary:')
            ->and($html)->toContain('.dark{--color-primary:')
            ->and($html)->toContain('ui-primary-btn');

        // Accent marks the active conversation on its page.
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $showHtml = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();
        expect($showHtml)->toContain('ui-accent-soft');

        // No second theme toggle and no competing color storage.
        expect(substr_count($html, 'window.__setTheme'))->toBeGreaterThanOrEqual(1);

        $js = file_get_contents(resource_path('js/app.js'));

        // Colors never live in JS storage: server DB is the single source,
        // preview is inline CSS vars only. Theme keeps its single write path.
        expect($js)->not()->toContain('--color-primary')
            ->and($js)->not()->toContain('ui-colors')
            ->and($js)->not()->toContain('ui_colors');
    });

    test('exactly one toast renderer and one settings modal exist', function () {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('conversations.index'))->assertOk()->getContent();

        expect(substr_count($html, 'aria-label="التنبيهات"'))->toBe(1)
            ->and(substr_count($html, 'aria-labelledby="settings-title"'))->toBe(1)
            ->and($html)->toContain('@toast.window');
    });
});
