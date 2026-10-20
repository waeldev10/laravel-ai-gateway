<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\PersonaController;
use App\Http\Controllers\Api\V1\UsageStatusController;
use Illuminate\Support\Facades\Route;

// Public auth entry points mirroring the Web guest routes below:
// same validation rules, same brute-force budgets, JSON instead
// of redirects, Sanctum bearer instead of a session.
Route::post('v1/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:register')
    ->name('api.v1.auth.register');
Route::post('v1/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.v1.auth.login');

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->group(function () {
        Route::get('me', MeController::class)->name('api.v1.me');
        Route::get('usage-status', UsageStatusController::class)->name('api.v1.usage-status');

        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

        Route::apiResource('conversations', ConversationController::class)->names([
            'index' => 'api.v1.conversations.index',
            'store' => 'api.v1.conversations.store',
            'show' => 'api.v1.conversations.show',
            'update' => 'api.v1.conversations.update',
            'destroy' => 'api.v1.conversations.destroy',
        ]);

        Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])
            ->name('api.v1.conversations.messages.index');
        Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:api-ai')
            ->name('api.v1.conversations.messages.store');
        Route::patch('conversations/{conversation}/messages/{message}', [MessageController::class, 'update'])
            ->name('api.v1.conversations.messages.update');
        Route::post('conversations/{conversation}/messages/continue', [MessageController::class, 'continue'])
            ->middleware('throttle:api-ai')
            ->name('api.v1.conversations.messages.continue');
        Route::post('conversations/{conversation}/messages/{message}/regenerate', [MessageController::class, 'regenerate'])
            ->middleware('throttle:api-ai')
            ->name('api.v1.conversations.messages.regenerate');

        Route::post('conversations/{conversation}/pin', [ConversationController::class, 'pin'])
            ->name('api.v1.conversations.pin');
        Route::delete('conversations/{conversation}/pin', [ConversationController::class, 'unpin'])
            ->name('api.v1.conversations.unpin');
        Route::delete('conversations', [ConversationController::class, 'destroyMany'])
            ->name('api.v1.conversations.destroyMany');

        Route::apiResource('personas', PersonaController::class)->names([
            'index' => 'api.v1.personas.index',
            'store' => 'api.v1.personas.store',
            'show' => 'api.v1.personas.show',
            'update' => 'api.v1.personas.update',
            'destroy' => 'api.v1.personas.destroy',
        ]);
        Route::post('personas/{persona}/activate', [PersonaController::class, 'activate'])
            ->name('api.v1.personas.activate');
        Route::post('personas/{persona}/deactivate', [PersonaController::class, 'deactivate'])
            ->name('api.v1.personas.deactivate');
    });
