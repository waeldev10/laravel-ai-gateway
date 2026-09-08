<?php

use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('registration screen can be rendered', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('إنشاء حساب');
});

test('new users can register', function () {
    $response = $this->post(route('register'), [
        'name' => 'مستخدم تجريبي',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard'));

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not()->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('session is regenerated after registration', function () {
    $session = Mockery::mock(Session::class);
    $session->shouldReceive('regenerate')->once();

    $request = Request::create(route('register'), 'POST', [
        'name' => 'مستخدم تجريبي',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    $request->setLaravelSession($session);

    (new AuthenticationService)->register($request->only(['name', 'email', 'password']), $request);

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not()->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('registration fails with invalid data', function () {
    $this->post(route('register'), [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    $this->assertGuest();
});

test('registration fails when the email is already taken', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register'), [
        'name' => 'مستخدم آخر',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertSessionHasErrors('email');
});
