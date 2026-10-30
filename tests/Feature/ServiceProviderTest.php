<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LEXO\LaravelHealthEndpoints\Providers\LaravelHealthEndpointsServiceProvider;

it('merges the package config', fn () => expect(config('laravel-health-endpoints.cache_ttl'))->toBe(10)
    ->and(config('laravel-health-endpoints.details'))->toBe('auto')
    ->and(config('laravel-health-endpoints.markers.header'))->toBe('<template id="lhe-service-up-header">UP</template>'));

it('registers the config publish tag', fn () => expect(ServiceProvider::pathsToPublish(LaravelHealthEndpointsServiceProvider::class, 'laravel-health-endpoints-config'))
    ->toHaveCount(1));

it('registers the health routes after boot', function () {
    expect(Route::has('lhe.all'))->toBeTrue()
        ->and(Route::has('lhe.ping'))->toBeTrue();

    $this->getJson('/lhe/v1/ping')->assertOk()->assertJsonPath('status', 'ok');
});
