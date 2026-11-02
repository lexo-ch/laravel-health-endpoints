<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\RouteCacheCommand;
use Illuminate\Support\Facades\Route;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\CustomPathCheck;

/*
 * Runs the framework's real `route:cache` against the package routes, then boots a fresh app the way production does:
 * routes come from the cache file and the provider must not register them a second time.
 */

function lheRegisterRouteCacheChecks(): void
{
    config([
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
    ]);

    LaravelHealthEndpoints::register(new OkFixtureCheck);
    LaravelHealthEndpoints::register(CustomPathCheck::class);
}

beforeEach(function () {
    // Per-test cache file, so the shared Testbench skeleton is never touched.
    $this->routeCachePath = tempnam(sys_get_temp_dir(), 'lhe-routes-').'.php';
    $_ENV['APP_ROUTES_CACHE'] = $_SERVER['APP_ROUTES_CACHE'] = $this->routeCachePath;
});

afterEach(function () {
    unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
    (new Filesystem)->delete([$this->routeCachePath, substr($this->routeCachePath, 0, -4)]);
});

it('serves every endpoint from a route:cache file with the security middleware intact', function () {
    lheRegisterRouteCacheChecks();
    lheReloadRoutes();
    $expectedRoutes = count(Route::getRoutes());

    // The real command, minus re-requiring bootstrap/app.php (the Testbench skeleton does not load this package).
    $this->app[Kernel::class]->registerCommand(new class(new Filesystem) extends RouteCacheCommand
    {
        protected function getFreshApplicationRoutes()
        {
            return tap(app('router')->getRoutes(), function ($routes) {
                $routes->refreshNameLookups();
                $routes->refreshActionLookups();
            });
        }
    });

    $this->artisan('route:cache')->assertSuccessful();
    expect(file_exists($this->routeCachePath))->toBeTrue();

    $this->refreshApplication();

    expect($this->app->routesAreCached())->toBeTrue()
        ->and(Route::has('lhe.ping'))->toBeFalse();

    // What Laravel's RouteServiceProvider does when routes are cached.
    require $this->app->getCachedRoutesPath();
    lheRegisterRouteCacheChecks();

    expect(count(Route::getRoutes()))->toBe($expectedRoutes);
    foreach (['lhe.all', 'lhe.ping', 'lhe.check', 'lhe.fallback', 'lhe.custom.custom-path'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }

    $this->getJson('/lhe/v1/ping')->assertOk()->assertJsonPath('status', 'ok')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('checks.ok-fixture.status', 'ok');
    $this->getJson('/lhe/v1/ok-fixture')->assertOk()->assertJsonPath('status', 'ok');
    $this->getJson('/lhe/v1/billing/stripe')->assertOk()->assertJsonPath('checks.custom-path.status', 'ok');
    $this->getJson('/lhe/v1/does-not-exist')->assertNotFound();
    $this->json('TRACE', '/lhe/v1/ping')->assertStatus(405);

    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1')->assertUnauthorized()->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
    $this->getJson('/lhe/v1/billing/stripe')->assertUnauthorized();
    $this->getJson('/lhe/v1', ['Authorization' => 'Bearer secret-token'])->assertOk();
});
