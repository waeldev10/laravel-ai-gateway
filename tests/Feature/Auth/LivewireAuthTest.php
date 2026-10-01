<?php

use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\RegisterForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('livewire login', function () {
    test('login page renders the livewire form with the same UI', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('تسجيل الدخول')
            ->assertSeeLivewire('auth.login-form')
            ->assertSee('تذكرني', false);
    });

    test('valid credentials authenticate and navigate to new chat without reload', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('conversations.create'));

        $this->assertAuthenticatedAs($user);
    });

    test('invalid credentials are rejected, email kept, password cleared', function () {
        $user = User::factory()->create();

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertSet('email', $user->email)
            ->assertSet('password', '');

        $this->assertGuest();
    });

    test('login validates empty input without authenticating', function () {
        Livewire::test(LoginForm::class)
            ->set('email', '')
            ->set('password', '')
            ->call('login')
            ->assertHasErrors(['email', 'password']);

        $this->assertGuest();
    });

    test('livewire login attempts are throttled like the http endpoint', function () {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LoginForm::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors(['email']);
        }

        // Even correct credentials are refused once throttled.
        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    });
});

describe('livewire register', function () {
    test('register page renders the livewire form with the same UI', function () {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('إنشاء حساب')
            ->assertSeeLivewire('auth.register-form');
    });

    test('valid registration authenticates and navigates to new chat without reload', function () {
        Livewire::test(RegisterForm::class)
            ->set('name', 'مستخدم تجريبي')
            ->set('email', 'livewire-new@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('conversations.create'));

        $user = User::where('email', 'livewire-new@example.com')->first();

        expect($user)->not()->toBeNull()
            ->and(Hash::check('password', $user->password))->toBeTrue();

        $this->assertAuthenticatedAs($user);
    });

    test('invalid registration is rejected and passwords are cleared', function () {
        Livewire::test(RegisterForm::class)
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->set('password', 'short')
            ->set('password_confirmation', 'different')
            ->call('register')
            ->assertHasErrors(['name', 'email', 'password'])
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');

        $this->assertGuest();
        expect(User::count())->toBe(0);
    });

    test('taken email is rejected', function () {
        User::factory()->create(['email' => 'livewire-taken@example.com']);

        Livewire::test(RegisterForm::class)
            ->set('name', 'مستخدم آخر')
            ->set('email', 'livewire-taken@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    });
});
