<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('conversations.create')
        : redirect()->route('login');
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
    Route::get('conversations', [ConversationController::class, 'index'])
        ->name('conversations.index');
    Route::get('conversations/create', [ConversationController::class, 'create'])
        ->name('conversations.create');
    Route::post('conversations', [ConversationController::class, 'store'])
        ->name('conversations.store');
    Route::delete('conversations', [ConversationController::class, 'destroyMany'])
        ->name('conversations.destroyMany');
    Route::get('conversations/search', [ConversationController::class, 'search'])
        ->name('conversations.search');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
        ->name('conversations.show');
    Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])
        ->name('conversations.update');
    Route::patch('conversations/{conversation}/pin', [ConversationController::class, 'togglePin'])
        ->name('conversations.pin');
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])
        ->name('conversations.destroy');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->name('conversations.messages.store');

    Route::get('profile', [ProfileController::class, 'show'])
        ->name('profile.show');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
