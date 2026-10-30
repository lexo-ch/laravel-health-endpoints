<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use LEXO\LaravelHealthEndpoints\Checks\RedisCheck;

function lheRedisReachable(): bool
{
    $socket = @fsockopen(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), $errno, $error, 0.2);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.details' => 'always',
        'laravel-health-endpoints.redis.enabled' => true,
        'database.redis.client' => 'predis',
        'database.redis.default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
        ],
    ]);
    Cache::store('array')->flush();
    lheReloadRoutes();
});

it('fails fast on an unreachable redis', function () {
    config([
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
        'database.redis.default.password' => 'sup3rsecret',
        'laravel-health-endpoints.timeout' => 1,
    ]);

    $start = microtime(true);
    $response = $this->getJson('/lhe/v1/redis')->assertStatus(503);

    expect((microtime(true) - $start) * 1000)->toBeLessThan(3000)
        ->and($response->json('checks.redis.targets.default.message'))->toBe('Connection refused')
        ->and($response->getContent())->not->toContain('sup3rsecret')->not->toContain('127.0.0.1');
});

it('pings a live redis', function () {
    $this->getJson('/lhe/v1/redis')->assertOk()
        ->assertJsonPath('checks.redis.targets.default.status', 'ok')
        ->assertJsonPath('checks.redis.targets.default.meta.client', 'predis');
})->skip(fn () => ! lheRedisReachable(), 'No Redis server reachable');

it('fails an unconfigured connection', function () {
    config(['laravel-health-endpoints.redis.connections' => 'default,ghost']);

    $this->getJson('/lhe/v1/redis/ghost')->assertStatus(503)
        ->assertJsonPath('checks.redis.targets.ghost.message', 'Connection not configured');
});

it('does not treat reserved config keys as connections', function () {
    config(['laravel-health-endpoints.redis.connections' => 'options']);

    $this->getJson('/lhe/v1/redis/options')->assertStatus(503)
        ->assertJsonPath('checks.redis.targets.options.message', 'Connection not configured');
});

it('applies the configured timeout to the probe config', function () {
    $config = (new RedisCheck)->probeConfig(['host' => 'h', 'timeout' => 99], 4);

    expect($config)->toMatchArray(['host' => 'h', 'timeout' => 4, 'read_timeout' => 4, 'read_write_timeout' => 4]);
});
