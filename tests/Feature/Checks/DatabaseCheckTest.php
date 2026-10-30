<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.details' => 'always',
        'laravel-health-endpoints.redis.enabled' => false,
        'database.default' => 'main',
        'database.connections.main' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'database.connections.second' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);
    Cache::store('array')->flush();
    lheReloadRoutes();
});

it('checks the default connection', function () {
    $this->getJson('/lhe/v1/database')->assertOk()
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.database.targets.main.status', 'ok')
        ->assertJsonPath('checks.database.targets.main.meta.driver', 'sqlite');
});

it('checks multiple connections', function () {
    config(['laravel-health-endpoints.database.connections' => 'main, second']);

    $this->getJson('/lhe/v1/database')->assertOk()
        ->assertJsonPath('checks.database.targets.main.status', 'ok')
        ->assertJsonPath('checks.database.targets.second.status', 'ok');
});

it('supports a single connection route', function () {
    config(['laravel-health-endpoints.database.connections' => 'main,second']);

    $this->getJson('/lhe/v1/database/second')->assertOk()
        ->assertJsonPath('checks.database.targets.second.status', 'ok')
        ->assertJsonMissingPath('checks.database.targets.main');
});

it('fails an unconfigured connection', function () {
    config(['laravel-health-endpoints.database.connections' => 'main,ghost']);

    $this->getJson('/lhe/v1/database/ghost')->assertStatus(503)
        ->assertJsonPath('checks.database.targets.ghost.message', 'Connection not configured');
});

it('handles connection names containing dots', function () {
    config([
        'database.connections' => array_merge(config('database.connections'), [
            'main.read' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]),
        'laravel-health-endpoints.database.connections' => 'main.read',
    ]);

    $response = $this->getJson('/lhe/v1/database/main.read')->assertOk();

    expect($response->json('checks.database.targets'))->toHaveKey('main.read')
        ->and($response->json('checks.database.targets')['main.read']['status'])->toBe('ok');
    expect(array_keys(config('database.connections')))->not->toContain('lhe_probe_main.read');
});

it('fails fast on an unreachable mysql server with a safe message', function () {
    config([
        'database.connections.down' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app',
            'username' => 'root', 'password' => 'sup3rsecret',
        ],
        'laravel-health-endpoints.database.connections' => 'down',
        'laravel-health-endpoints.timeout' => 1,
    ]);

    $start = microtime(true);
    $response = $this->getJson('/lhe/v1/database/down')->assertStatus(503);

    expect((microtime(true) - $start) * 1000)->toBeLessThan(3000);
    $response->assertJsonPath('checks.database.targets.down.message', 'Connection refused');
    expect($response->getContent())->not->toContain('127.0.0.1')->not->toContain('sup3rsecret');
})->skip(! extension_loaded('pdo_mysql'), 'pdo_mysql not available');

it('leaves no probe connection behind and does not touch the app connection', function () {
    $before = DB::connection()->getPdo();

    $this->getJson('/lhe/v1/database')->assertOk();

    expect(DB::connection()->getPdo())->toBe($before)
        ->and(array_keys(config('database.connections')))->not->toContain('lhe_probe_main')
        ->and(array_keys(DB::getConnections()))->not->toContain('lhe_probe_main');
});
