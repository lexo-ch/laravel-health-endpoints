<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Providers;

use Illuminate\Support\ServiceProvider;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class LaravelHealthEndpointsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/laravel-health-endpoints.php', 'laravel-health-endpoints');

        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
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
