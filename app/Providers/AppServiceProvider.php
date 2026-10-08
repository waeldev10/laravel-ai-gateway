<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Policies\ConversationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email').'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // HTTP-level guard for the expensive AI streaming endpoints, keyed
        // by user (or IP for safety). Separate from the application-level
        // generation budget enforced inside MessageService and from any
        // upstream provider rate limits.
        RateLimiter::for('ai-stream', function (Request $request) {
            $key = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(20)->by('ai-stream:'.$key);
        });
    }
}
