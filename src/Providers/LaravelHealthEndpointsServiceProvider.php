<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Providers;

use Illuminate\Support\ServiceProvider;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Runner\StateTracker;
use LEXO\LaravelHealthEndpoints\Support\ResultCache;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class LaravelHealthEndpointsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/laravel-health-endpoints.php', 'laravel-health-endpoints');

        $this->app->singleton(Settings::class);
        $this->app->singleton(HealthCheckRegistry::class);
        $this->app->singleton(StateTracker::class);
        $this->app->singleton(HealthRunner::class);
        $this->app->singleton(ResultCache::class);
    }

    public function boot(): void
    {
        // After every provider has booted, so checks registered in app providers get their custom routes.
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/laravel-health-endpoints.php' => config_path('laravel-health-endpoints.php'),
            ], 'laravel-health-endpoints-config');

            $this->publishes([
                __DIR__.'/../../stubs' => base_path('stubs/lhe'),
            ], 'laravel-health-endpoints-stubs');
        }
    }
}
