<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login is rate limited after multiple attempts', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])
        ->assertStatus(429);
});

test('login rate limit is enforced per credential and source', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post(route('login'), [
        'email' => 'other@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(302);
});

test('registration is rate limited per source', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('register'), [
            'name' => "مستخدم $i",
            'email' => "user$i@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    $this->post(route('register'), [
        'name' => 'مستخدم محظور',
        'email' => 'blocked@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertStatus(429);
});
