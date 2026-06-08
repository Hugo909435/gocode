<?php

namespace App\Providers;

use App\Agent\AgentManager;
use App\Contracts\AgentDriverContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgentManager::class);

        // Lie le contrat au driver résolu par l'AgentManager (selon config('agent.default'))
        $this->app->bind(AgentDriverContract::class, function ($app) {
            return $app->make(AgentManager::class)->driver();
        });
    }

    public function boot(): void
    {
        //
    }
}
