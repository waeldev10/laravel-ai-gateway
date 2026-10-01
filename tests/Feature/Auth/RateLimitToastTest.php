<?php

use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\RegisterForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('rate limit toast', function () {
    test('throttled login fires the unified error toast and stays on the page', function () {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LoginForm::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors(['email']);
        }

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertDispatched('toast',
                type: 'error',
                message: 'لقد حاولت 5 مرات، الرجاء الانتظار.'
            )
            ->assertSet('email', $user->email)
            ->assertSet('password', '');

        $this->assertGuest();
    });

    test('throttled registration fires the unified error toast and stays on the page', function () {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(RegisterForm::class)
                ->set('name', "مستخدم $i")
                ->set('email', "throttled$i@example.com")
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->call('register')
                ->assertHasNoErrors();
        }

        Livewire::test(RegisterForm::class)
            ->set('name', 'مستخدم محظور')
            ->set('email', 'throttled-blocked@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['email'])
            ->assertDispatched('toast',
                type: 'error',
                message: 'لقد حاولت 5 مرات، الرجاء الانتظار.'
            );

        // Blocked: no new user, and the loop users are untouched.
        expect(User::where('email', 'throttled-blocked@example.com')->exists())->toBeFalse()
            ->and(User::count())->toBe(5);
    });
});
