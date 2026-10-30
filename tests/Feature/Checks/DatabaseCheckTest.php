<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LEXO\LaravelHealthEndpoints\Checks\DatabaseCheck;

function lheAssertNoProbeResidue(): void
{
    $isProbe = static fn (string $key): bool => str_starts_with($key, 'lhe_probe_');

    expect(array_filter(array_keys(config('database.connections')), $isProbe))->toBe([])
        ->and(array_filter(array_keys(DB::getConnections()), $isProbe))->toBe([]);
}

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
    lheAssertNoProbeResidue();
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
    lheAssertNoProbeResidue();
    expect($response->getContent())->not->toContain('127.0.0.1')->not->toContain('sup3rsecret');
})->skip(! extension_loaded('pdo_mysql'), 'pdo_mysql not available');

it('leaves no probe connection behind and does not touch the app connection', function () {
    $before = DB::connection()->getPdo();

    $this->getJson('/lhe/v1/database')->assertOk();

    expect(DB::connection()->getPdo())->toBe($before);
    lheAssertNoProbeResidue();

    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-lhe-dir/x.sqlite'],
        'laravel-health-endpoints.database.connections' => 'main,broken',
    ]);
    $this->getJson('/lhe/v1/database/broken')->assertStatus(503)
        ->assertJsonPath('checks.database.targets.broken.status', 'fail');
    lheAssertNoProbeResidue();
});

it('passes the configured timeout into the probe config', function () {
    config(['laravel-health-endpoints.timeout' => 7]);

    $spy = new class extends DatabaseCheck
    {
        public ?int $timeout = null;

        public function probeConfig(array $config, int $timeout): array
        {
            $this->timeout = $timeout;

            return parent::probeConfig($config, $timeout);
        }
    };

    expect($spy->runTarget('main')->isOk())->toBeTrue()
        ->and($spy->timeout)->toBe(7);
});

it('applies the driver-specific connect timeout to the probe config', function () {
    $check = new DatabaseCheck;

    foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
        $config = $check->probeConfig(['driver' => $driver, 'options' => [PDO::ATTR_PERSISTENT => true]], 4);
        expect($config['options'][PDO::ATTR_TIMEOUT])->toBe(4)
            ->and($config['options'][PDO::ATTR_PERSISTENT])->toBeTrue()
            ->and($config)->not->toHaveKey('connect_timeout');
    }

    expect($check->probeConfig(['driver' => 'sqlsrv'], 4)['login_timeout'])->toBe(4)
        ->and($check->probeConfig(['driver' => 'sqlite', 'database' => ':memory:'], 4))
        ->toBe(['driver' => 'sqlite', 'database' => ':memory:']);
});
