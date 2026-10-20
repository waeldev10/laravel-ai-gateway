<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Support\AiProviderResolver;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    
    public function register(): void
    {
        $this->app->bind(
            AiProvider::class,
            fn ($app) => $app->make(AiProviderResolver::class)->resolve()
        );
    }
}
