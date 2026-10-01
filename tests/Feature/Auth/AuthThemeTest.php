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
            ->and($source)->not()->toContain('setTimeout');
    });
});
