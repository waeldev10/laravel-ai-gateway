<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

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

function uiColorHexRule(): string
{
    // Strict #RRGGBB format shared by the browser validation and these tests.
    return 'regex:/^#[0-9a-fA-F]{6}$/';
}

function uiColorModalPath(): string
{
    return resource_path('views/components/settings/ui-color-settings.blade.php');
}

function uiColorLightDefaults(): array
{
    return array_map(
        fn ($v) => strtoupper((string) $v),
        (array) config('ui.colors.defaults.light', [])
    );
}

describe('settings user menu', function () {
    test('menu contains profile, settings and logout in order', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('conversations.search'))
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

        $modal = file_get_contents(uiColorModalPath());

        expect($modal)->toContain('@open-settings-modal.window')
            ->and($modal)->toContain('@close-settings-modal.window')
            ->and($modal)->toContain('@keydown.escape.window');
    });

    test('settings UI is a Blade component, not a Livewire component', function () {
        expect(class_exists('App\Livewire\Settings\UiColorSettings'))->toBeFalse()
            ->and(class_exists('App\Services\Theme\UiColorService'))->toBeFalse()
            ->and(file_exists(resource_path('views/livewire/Settings/ui-color-settings.blade.php')))->toBeFalse()
            ->and(file_exists(uiColorModalPath()))->toBeTrue();

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        expect($layout)->toContain('<x-settings.ui-color-settings />')
            ->and($layout)->not()->toContain("@livewire('settings.ui-color-settings')");

        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('conversations.search'))->assertOk()->getContent();

        // The modal renders server-side with zero Livewire involvement.
        expect($html)->toContain('aria-labelledby="settings-title"');
    });
});

describe('settings modal', function () {
    test('modal is reusable, accessible, responsive and theme-aware', function () {
        $source = file_get_contents(uiColorModalPath());

        expect($source)->toContain('role="dialog"')
            ->and($source)->toContain('aria-modal="true"')
            ->and($source)->toContain('aria-labelledby="settings-title"')
            ->and($source)->toContain('fixed inset-0 z-[60]')
            ->and($source)->toContain('max-w-md')
            ->and($source)->toContain('dark:bg-zinc-900')
            ->and($source)->toContain('aria-label="إغلاق الإعدادات"');
    });

    test('color picking previews without a server request', function () {
        $source = file_get_contents(uiColorModalPath());

        // Native color controls bound to Alpine draft only; preview goes
        // through the shared browser helper (no direct CSS writes here).
        expect(substr_count($source, 'type="color"'))->toBe(4)
            ->and($source)->toContain('x-model="draft.primary"')
            ->and($source)->toContain('x-model="draft.primary_text"')
            ->and($source)->toContain('x-model="draft.accent"')
            ->and($source)->toContain('x-model="draft.link"')
            ->and($source)->toContain('@input="preview()"')
            ->and($source)->toContain('a.apply(c)')
            ->and($source)->not()->toContain('wire:model')
            ->and($source)->not()->toContain('setProperty');
    });

    test('saving and resetting never touch the server', function () {
        $source = file_get_contents(uiColorModalPath());

        // Single source of truth: the modal delegates storage and CSS to
        // window.__uiColors (via api()) and never touches localStorage,
        // CSS, or the server itself. The strict pattern lives once in the
        // head helper plus the tiny PHP fallback sanitizer below it.
        // No Livewire roundtrip exists.
        expect($source)->toContain('@click="save()"')
            ->and($source)->toContain('@click="resetColors()"')
            ->and($source)->toContain('a.save(')
            ->and($source)->toContain('a.clear()')
            ->and($source)->toContain('a.read()')
            ->and($source)->toContain('a.sanitize(')
            ->and($source)->toContain('استعادة الألوان الافتراضية')
            ->and($source)->not()->toContain('localStorage.')
            ->and($source)->not()->toContain('setProperty')
            ->and($source)->not()->toContain('removeProperty')
            ->and($source)->not()->toContain('$wire.save')
            ->and($source)->not()->toContain('$wire.resetToDefaults')
            ->and($source)->not()->toContain('wire:loading')
            ->and($source)->not()->toContain('setTimeout')
            ->and($source)->not()->toContain('innerHTML')
            ->and($source)->not()->toContain('eval(');
    });
});

describe('browser-local persistence', function () {
    test('no database column, cookie, service, or Livewire action persists UI colors', function () {
        expect(Schema::hasColumn('users', 'ui_colors'))->toBeFalse()
            ->and(class_exists('App\Livewire\Settings\UiColorSettings'))->toBeFalse()
            ->and(class_exists('App\Services\Theme\UiColorService'))->toBeFalse();

        $user = User::factory()->create();
        expect(array_key_exists('ui_colors', $user->getAttributes()))->toBeFalse();

        $response = $this->actingAs($user)->get(route('conversations.search'))->assertOk();
        $cookieNames = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());

        expect($cookieNames)->not()->toContain('ui_colors');
    });

    test('only strict #RRGGBB palettes are accepted by the shared format', function () {
        foreach ([
            ['primary' => 'red', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '#2563EB', 'primary_text' => 'url(evil)', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '#FFF', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '<script>', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
            ['primary' => '#2563EB;expression(x)', 'primary_text' => '#FFFFFF', 'accent' => '#7C3AED', 'link' => '#1D4ED8'],
        ] as $bad) {
            $validator = Validator::make($bad, [
                'primary' => ['required', 'string', uiColorHexRule()],
                'primary_text' => ['required', 'string', uiColorHexRule()],
                'accent' => ['required', 'string', uiColorHexRule()],
                'link' => ['required', 'string', uiColorHexRule()],
            ]);

            expect($validator->fails())->toBeTrue();
        }

        $good = Validator::make(validPalette(), [
            'primary' => ['required', 'string', uiColorHexRule()],
            'primary_text' => ['required', 'string', uiColorHexRule()],
            'accent' => ['required', 'string', uiColorHexRule()],
            'link' => ['required', 'string', uiColorHexRule()],
        ]);

        expect($good->passes())->toBeTrue();

        // The shared browser helper enforces the same strictness client-side;
        // the modal delegates to it and toasts instead of persisting.
        $head = file_get_contents(resource_path('views/components/theme/ui-colors.blade.php'));
        $modal = file_get_contents(uiColorModalPath());

        expect($head)->toContain('/^#[0-9a-fA-F]{6}$/')
            ->and($head)->toContain('setProperty')
            ->and(substr_count($head, 'Object.keys(out).length !== TOKENS.length'))->toBe(2)
            ->and($head)->not()->toContain('innerHTML')
            ->and($head)->not()->toContain('eval(')
            ->and($modal)->toContain('a.save(')
            ->and($modal)->toContain("'تعذر الحفظ'");
    });

    test('rendered CSS carries config defaults while stored colors apply client-side', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $defaults = uiColorLightDefaults();

        foreach ([route('conversations.search'), route('conversations.create'), route('profile.show')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            expect($html)->toContain('<style id="ui-colors">')
                ->and($html)->toContain($defaults['primary'])
                ->and($html)->toContain('window.__uiColors')
                ->and($html)->toContain('localStorage.getItem(KEY)')
                ->and($html)->toContain('/^#[0-9a-fA-F]{6}$/');
        }

        // Authenticated rendering is identical for every user: no per-user
        // values ever reach the server output.
        $other = User::factory()->create();
        $html = $this->actingAs($other)->get(route('conversations.search'))->assertOk()->getContent();
        $dark = array_map(fn ($v) => strtoupper((string) $v), (array) config('ui.colors.defaults.dark', []));

        expect($html)->toContain($defaults['primary'])
            ->and($html)->toContain($dark['primary']);
    });

    test('reset path clears the browser palette and needs no server state', function () {
        $modal = file_get_contents(uiColorModalPath());
        $head = file_get_contents(resource_path('views/components/theme/ui-colors.blade.php'));

        expect($modal)->toContain('resetColors()')
            ->and($modal)->toContain('clearAll()')
            ->and($head)->toContain('localStorage.removeItem(KEY)')
            ->and($head)->toContain('removeProperty')
            ->and($modal)->not()->toContain('$wire.resetToDefaults');

        expect(class_exists('App\Livewire\Settings\UiColorSettings'))->toBeFalse();
    });

    test('hostile config values never reach the rendered CSS', function () {
        config()->set('ui.colors.defaults.light.primary', 'red');
        config()->set('ui.colors.defaults.light.primary_text', 'url(evil)');
        config()->set('ui.colors.defaults.dark.accent', '#123456;background:red');
        config()->set('ui.colors.defaults.dark.link', '</style><script>alert(1)</script>');
        config()->set('ui.colors.defaults.light.link', ['not', 'a', 'string']);

        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('conversations.search'))->assertOk()->getContent();

        expect($html)->not()->toContain('url(evil)')
            ->and($html)->not()->toContain('background:red')
            ->and($html)->not()->toContain('alert(1)')
            ->and($html)->toContain('--color-primary:#000000');
    });

    test('config is the single source for defaults and values are sanitized', function () {
        expect(config('ui.colors.tokens'))->toBe(['primary', 'primary_text', 'accent', 'link']);

        $source = file_get_contents(resource_path('views/components/theme/ui-colors.blade.php'));

        // One style tag, values only from explicit config reads (no service,
        // no loops, no palette copy: only neutral per-token fallbacks).
        expect(substr_count($source, '<style id="ui-colors">'))->toBe(1)
            ->and($source)->toContain("config('ui.colors.defaults.light.primary')")
            ->and($source)->toContain("config('ui.colors.defaults.dark.primary')")
            ->and($source)->not()->toContain('UiColorService')
            ->and($source)->not()->toContain('effectiveFor')
            ->and($source)->not()->toContain('auth()->user()')
            ->and($source)->not()->toContain('foreach')
            ->and($source)->not()->toContain('#ffffff')
            ->and($source)->not()->toContain('#FFFFFF');

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

        $html = $this->actingAs($user)->get(route('conversations.search'))->assertOk()->getContent();

        expect($html)->toContain(':root{')
            ->and($html)->toContain('.dark{')
            ->and($html)->toContain('--color-primary:')
            ->and($html)->toContain('ui-primary-btn');

        // Accent marks the active conversation on its page.
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $showHtml = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk()->getContent();
        expect($showHtml)->toContain('ui-accent-soft');

        // No second theme toggle and no competing color storage.
        expect(substr_count($html, 'window.__setTheme'))->toBeGreaterThanOrEqual(1);

        $js = file_get_contents(resource_path('js/app.js'));

        // Colors persist like the theme (localStorage read + re-apply on
        // navigation/storage) but the bundle never writes them and never
        // calls the server for them. One direct guarded call, no wrapper
        // indirection. Theme keeps its single write path.
        expect($js)->toContain('ui_colors')
            ->and($js)->toContain('window.__uiColors.applyStored()')
            ->and($js)->not()->toContain('__applyUiColors')
            ->and($js)->not()->toContain("localStorage.setItem('ui_colors'")
            ->and($js)->toContain("localStorage.setItem('theme'");
    });

    test('exactly one toast renderer and one settings modal exist', function () {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('conversations.search'))->assertOk()->getContent();

        expect(substr_count($html, 'aria-label="التنبيهات"'))->toBe(1)
            ->and(substr_count($html, 'aria-labelledby="settings-title"'))->toBe(1)
            ->and($html)->toContain('@toast.window');
    });
});
