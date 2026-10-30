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
    }
}
