<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('api response contract', function () {
    test('errors follow RFC 9457 problem details with matching status', function () {
        $response = $this->getJson(route('api.v1.conversations.index'))
            ->assertUnauthorized();

        $problem = $response->json();

        expect(array_keys($problem))->toBe(['type', 'title', 'status', 'detail', 'code'])
            ->and($problem['status'])->toBe(401)
            ->and($problem['type'])->toContain('unauthenticated')
            ->and($problem['code'])->toBe('unauthenticated');
    });

    test('validation problems carry the errors member', function () {
        $user = User::factory()->create();

        $response = $this->withToken(apiToken($user))
            ->postJson(route('api.v1.conversations.store'), [])
            ->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')
            ->and($response->json('status'))->toBe(422)
            ->and($response->json('errors'))->toBeArray();
    });

    test('missing resources are problems, not raw exceptions', function () {
        $user = User::factory()->create();

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.show', '01aaaaaaaaaaaaaaaaaaaaaaaa'))
            ->assertNotFound();

        expect($response->json('code'))->toBe('not_found')
            ->and($response->json('status'))->toBe(404)
            ->and($response->getContent())->not()->toContain('trace');
    });

    test('collections wrap data with meta', function () {
        $user = User::factory()->create();
        $user->conversations()->create(['title' => 'One']);

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.index'))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(1, 'data');
    });

    test('actions return data null with a message', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $token = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson(route('api.v1.auth.logout'))
            ->assertOk()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data', null);
    });

    test('resources expose exactly their public representation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->getKey()]);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Hello',
        ]);

        $shown = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.show', $conversation))
            ->assertOk()
            ->json('data');

        expect(array_keys($shown))->toBe(['id', 'title', 'is_pinned', 'pinned_at', 'created_at', 'updated_at'])
            ->and($shown)->not()->toHaveKey('user_id');

        $listed = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.conversations.messages.index', $conversation))
            ->assertOk()
            ->json('data.0');

        expect(array_keys($listed))->toBe(['id', 'role', 'content', 'status', 'created_at'])
            ->and($listed)->not()->toHaveKeys(['conversation_id', 'html']);

        $me = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->json('data');

        expect(array_keys($me))->toBe(['id', 'name', 'email'])
            ->and($me)->not()->toHaveKeys(['password', 'remember_token']);
    });
});
