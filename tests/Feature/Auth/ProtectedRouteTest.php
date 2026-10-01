<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('unauthenticated users are redirected to the login screen', function () {
    $this->get(route('conversations.create'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access protected areas', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('conversations.create'))
        ->assertOk();
});

test('authenticated users are redirected away from guest screens', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('conversations.create'));

    $this->actingAs($user)
        ->get(route('register'))
        ->assertRedirect(route('conversations.create'));
});

test('guests visiting the root are redirected to login', function () {
    $this->get('/')
        ->assertRedirect(route('login'));
});

test('authenticated users visiting the root are redirected to new chat', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('conversations.create'));
});
