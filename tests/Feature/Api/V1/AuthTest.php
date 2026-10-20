<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

describe('api auth register', function () {
    test('a user can register and immediately use the issued bearer', function () {
        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Api User',
            'email' => 'api-user@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token', 'token_type']])
            ->assertJsonPath('data.user.email', 'api-user@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        // Exactly the UserResource representation: no password hash,
        // remember token, or model internals ever leave the API.
        expect(array_keys($response->json('data.user')))->toBe(['id', 'name', 'email']);

        $stored = User::where('email', 'api-user@example.com')->firstOrFail();

        expect(Hash::check('secret-password', $stored->password))->toBeTrue()
            ->and($response->getContent())->not()->toContain('secret-password');

        $this->withToken($response->json('data.token'))
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.email', 'api-user@example.com');
    });

    test('registration validates like the web flow', function () {
        $this->postJson(route('api.v1.auth.register'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code', 'errors'])
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('status', 422);
    });

    test('duplicate emails and unconfirmed passwords are rejected', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('email');

        $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Someone',
            'email' => 'fresh@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'different-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        expect(User::where('email', 'fresh@example.com')->exists())->toBeFalse();
    });

    test('registration attempts are throttled like the web flow', function () {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(route('api.v1.auth.register'), [
                'name' => "User {$i}",
                'email' => "throttled{$i}@example.com",
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
            ])->assertCreated();
        }

        $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Blocked',
            'email' => 'blocked@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
    });
});

describe('api auth login', function () {
    test('valid credentials issue a working bearer', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token', 'token_type']])
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.token_type', 'Bearer');

        expect($response->getContent())->not()->toContain('secret-password');

        $this->withToken($response->json('data.token'))
            ->getJson(route('api.v1.conversations.index'))
            ->assertOk();
    });

    test('wrong passwords and unknown emails share one safe shape', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $wrong = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $unknown = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        expect(array_keys($wrong->json()))->toBe(array_keys($unknown->json()))
            ->and($wrong->json('detail'))->toBe($unknown->json('detail'));

        expect($user->tokens()->count())->toBe(0);
    });

    test('login validates the payload', function () {
        $this->postJson(route('api.v1.auth.login'), ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    });

    test('repeated failures hit the shared login throttle', function () {
        User::factory()->create([
            'email' => 'throttled-login@example.com',
            'password' => 'secret-password',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('api.v1.auth.login'), [
                'email' => 'throttled-login@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'throttled-login@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
    });
});

describe('api auth logout', function () {
    test('logout revokes only the current bearer', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $first = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json('data.token');

        $second = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json('data.token');

        $this->withToken($first)
            ->postJson(route('api.v1.auth.logout'))
            ->assertOk()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'Logged out.');

        $this->app['auth']->forgetGuards();

        $this->withToken($first)
            ->getJson(route('api.v1.me'))
            ->assertUnauthorized();

        $this->withToken($second)
            ->getJson(route('api.v1.me'))
            ->assertOk();
    });

    test('unauthenticated logout is rejected', function () {
        $this->postJson(route('api.v1.auth.logout'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});
