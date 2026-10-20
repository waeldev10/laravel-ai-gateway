<?php

use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function apiToken(User $user, array $abilities = []): string
{
    return $user->createToken('test', $abilities === [] ? ApiAbilities::all() : $abilities)->plainTextToken;
}

describe('api authentication', function () {
    test('unauthenticated requests are rejected with the shared error shape', function () {
        $this->getJson(route('api.v1.conversations.index'))
            ->assertUnauthorized()
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code'])
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('status', 401);
    });

    test('a valid bearer token is accepted', function () {
        $user = User::factory()->create();
        $token = apiToken($user);

        $this->withToken($token)
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    });

    test('an invalid bearer token is rejected', function () {
        $this->withToken('invalid-token-value')
            ->getJson(route('api.v1.me'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });

    test('a token without the required ability is rejected', function () {
        $user = User::factory()->create();
        $token = apiToken($user, [ApiAbilities::CONVERSATIONS_READ]);

        $this->withToken($token)
            ->postJson(route('api.v1.conversations.store'), ['title' => 'Nope'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'code']);
    });

    test('a read-scoped token can read but not generate', function () {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Scoped']);
        $token = apiToken($user, [ApiAbilities::CONVERSATIONS_READ, ApiAbilities::MESSAGES_READ]);

        $this->withToken($token)
            ->getJson(route('api.v1.conversations.show', $conversation))
            ->assertOk();

        $this->withToken($token)
            ->postJson(route('api.v1.conversations.messages.store', $conversation), ['content' => 'hi'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    });
});
