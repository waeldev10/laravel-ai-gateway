<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('password visibility toggles', function () {
    test('the eye component is purely browser-side', function () {
        $source = file_get_contents(resource_path('views/components/ui/password-input.blade.php'));

        expect($source)->toContain('x-data="{ show: false }"')
            ->and($source)->toContain(':type="show ? \'text\' : \'password\'"')
            ->and($source)->toContain('@click="show = !show"')
            ->and($source)->not()->toContain('wire:model')
            ->and($source)->not()->toContain('wire:click')
            ->and($source)->not()->toContain('wire:submit')
            ->and($source)->not()->toContain('wire:loading');
    });

    test('login password has one independent toggle', function () {
        $html = $this->get(route('login'))->assertOk()->getContent();

        expect(substr_count($html, 'x-data="{ show: false }"'))->toBe(1)
            ->and($html)->toContain('إظهار كلمة المرور')
            ->and($html)->toContain('aria-pressed');
    });

    test('register passwords toggle independently', function () {
        $html = $this->get(route('register'))->assertOk()->getContent();

        expect(substr_count($html, 'x-data="{ show: false }"'))->toBe(2)
            ->and($html)->toContain('إظهار كلمة المرور');
    });

    test('profile password fields toggle independently', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->getContent();

        expect(substr_count($html, 'x-data="{ show: false }"'))->toBe(3);
    });

    test('password error styling is preserved', function () {
        $html = file_get_contents(resource_path('views/components/ui/password-input.blade.php'));

        expect($html)->toContain('border-red-500')
            ->and($html)->toContain('pe-10')
            ->and($html)->toContain('type="password"');
    });
});
