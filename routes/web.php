<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', fn () => view('dashboard'))
        ->name('dashboard');

    Route::get('conversations', [ConversationController::class, 'index'])
        ->name('conversations.index');
    Route::get('conversations/create', [ConversationController::class, 'create'])
        ->name('conversations.create');
    Route::post('conversations', [ConversationController::class, 'store'])
        ->name('conversations.store');
    Route::delete('conversations', [ConversationController::class, 'destroyMany'])
        ->name('conversations.destroyMany');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
        ->name('conversations.show');
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])
        ->name('conversations.destroy');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->name('conversations.messages.store');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
