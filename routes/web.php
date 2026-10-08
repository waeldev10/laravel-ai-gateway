<?php

use App\Http\Controllers\AiUsageStatusController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromptController;
use Illuminate\Http\Request;
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
    Route::get('conversations/create', [ConversationController::class, 'create'])
        ->name('conversations.create');
    Route::post('conversations', [ConversationController::class, 'store'])
        ->name('conversations.store');
    // Legacy listing URL: the standalone index page was removed in favor of
    // the dedicated search experience. Keep GET from ever 405ing (a DELETE
    // route shares this URI) by redirecting to search, preserving any query.
    Route::get('conversations', function (Request $request) {
        return redirect()->route('conversations.search', $request->query());
    });
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
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])
        ->name('conversations.messages.index');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->name('conversations.messages.store');
    // Real-time AI generation: normal HTTP endpoint returning an SSE
    // stream. The legacy redirect route above stays as the synchronous
    // fallback; the browser uses these streaming routes (fetch +
    // ReadableStream), never a Livewire action, for AI generation.
    Route::post('conversations/stream', [ChatStreamController::class, 'storeNew'])
        ->middleware('throttle:ai-stream')
        ->name('conversations.stream');
    Route::post('conversations/{conversation}/messages/stream', [ChatStreamController::class, 'store'])
        ->middleware('throttle:ai-stream')
        ->name('conversations.messages.stream');
    Route::post('conversations/{conversation}/messages/continue', [ChatStreamController::class, 'continue'])
        ->middleware('throttle:ai-stream')
        ->name('conversations.messages.continue');
    Route::post('conversations/{conversation}/messages/{message}/regenerate', [ChatStreamController::class, 'regenerate'])
        ->middleware('throttle:ai-stream')
        ->name('conversations.messages.regenerate');

    Route::get('profile', [ProfileController::class, 'show'])
        ->name('profile.show');

    Route::get('ai/usage-status', AiUsageStatusController::class)
        ->name('ai.usage-status');

    Route::get('prompts', [PromptController::class, 'index'])
        ->name('prompts.index');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
