<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LEXO\LaravelHealthEndpoints\Providers\LaravelHealthEndpointsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelHealthEndpointsServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Built-ins are opt-in per test file so unrelated tests never probe real services.
        foreach (['database', 'redis', 'cache', 'storage', 'queue', 'scheduler', 'migrations', 'debug_mode'] as $check) {
            $app['config']->set("laravel-health-endpoints.{$check}.enabled", false);
        }
    }
}
