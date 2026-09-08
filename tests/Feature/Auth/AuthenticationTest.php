<?php

use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('login screen can be rendered', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('تسجيل الدخول');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('session is regenerated after a successful login', function () {
    $user = User::factory()->create();

    $session = Mockery::mock(Session::class);
    $session->shouldReceive('regenerate')->once();

    $request = Request::create(route('login'), 'POST', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $request->setLaravelSession($session);

    (new AuthenticationService)->login([
        'email' => $user->email,
        'password' => 'password',
    ], false, $request);

    $this->assertAuthenticatedAs($user);
});

test('users cannot authenticate with an invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('failed login does not reveal whether the email exists', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHas('errors', function ($errors) {
        return $errors->first('email') === __('auth.failed');
    });

    $this->post(route('login'), [
        'email' => 'unknown@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHas('errors', function ($errors) {
        return $errors->first('email') === __('auth.failed');
    });

    $this->assertGuest();
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('session is invalidated and the token regenerated on logout', function () {
    $user = User::factory()->create();

    $session = Mockery::mock(Session::class);
    $session->shouldReceive('invalidate')->once();
    $session->shouldReceive('regenerateToken')->once();

    $request = Request::create(route('logout'), 'POST');
    $request->setLaravelSession($session);

    $this->actingAs($user);

    (new AuthenticationService)->logout($request);

    $this->assertGuest();
});

test('login requires valid input', function () {
    $this->post(route('login'), [
        'email' => null,
        'password' => null,
    ])
        ->assertSessionHasErrors(['email', 'password']);
});
