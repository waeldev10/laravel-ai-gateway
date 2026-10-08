<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('auth layout theme toggle', function () {
    test('login page offers the shared theme control without its own theme state', function () {
        $html = $this->get(route('login'))->assertOk()->getContent();

        // Three options delegating to the single implementation.
        expect(substr_count($html, 'window.__setTheme && window.__setTheme('))->toBe(3)
            ->and($html)->toContain('اختيار المظهر')
            ->and($html)->toContain('aria-pressed')
            // Pre-paint init still prevents a wrong-theme flash.
            ->and($html)->toContain("localStorage.getItem('theme')")
            // No second persist implementation: the rule lives once in app.js.
            ->and($html)->not()->toContain("localStorage.setItem('theme'");
    });

    test('register page offers the shared theme control without its own theme state', function () {
        $html = $this->get(route('register'))->assertOk()->getContent();

        expect(substr_count($html, 'window.__setTheme && window.__setTheme('))->toBe(3)
            ->and($html)->toContain('اختيار المظهر')
            ->and($html)->toContain("localStorage.getItem('theme')")
            ->and($html)->not()->toContain("localStorage.setItem('theme'");
    });

    test('the shipped theme module owns the single write path', function () {
        $source = file_get_contents(resource_path('js/app.js'));

        expect($source)->toContain('window.__setTheme')
            ->and($source)->toContain('localStorage.setItem')
            // Exactly one timer in the module, owned by the navigation
            // loading visibility gate. The theme write path itself stays
            // timer-free: no delayed repaints, no fake transitions.
            ->and(substr_count($source, 'setTimeout'))->toBe(1)
            ->and($source)->toContain('navLoadingTimer');
    });

    test('the theme class survives livewire navigation without a wrong-theme frame', function () {
        $source = file_get_contents(resource_path('js/app.js'));

        // Livewire navigate syncs <html> attributes from the
        // server-rendered document (which never carries the client
        // theme), stripping `dark`. The guard restores the expected
        // class synchronously before the next paint: no timers, no
        // masking, still one theme implementation.
        expect($source)->toContain('MutationObserver')
            ->and($source)->toContain("attributeFilter: ['class']")
            ->and($source)->toContain('expectedDark');
    });
});
