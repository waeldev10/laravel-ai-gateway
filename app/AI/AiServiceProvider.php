<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Support\AiProviderResolver;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Bind the provider contract to the configured implementation.
     *
     * Application code depends on AiProvider, never on concrete providers.
     * Resolution itself stays in AiProviderResolver, which remains the
     * single place that maps provider names to implementations.
     */
    public function register(): void
    {
        $this->app->bind(
            AiProvider::class,
            fn ($app) => $app->make(AiProviderResolver::class)->resolve()
        );
    }
}
