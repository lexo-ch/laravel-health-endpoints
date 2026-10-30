<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Exceptions\InvalidHealthCheckException;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckDiscovery;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\HealthChecks\DiskSpaceCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\BadNameCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\BadPathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\CollidingPathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\DupACheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\DupBCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\NewlineNameCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\NewlinePathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\NumericNameCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\OtherNamePathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\ReservedRedisCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\SamePathACheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\SamePathBCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\SelfNamePathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\ConfigFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\CountingCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\CustomPathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\FacadeFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\StubRedisCheck;

if (! function_exists('lheRegistryTestRegistry')) {
    function lheRegistryTestRegistry(): HealthCheckRegistry
    {
        return app(HealthCheckRegistry::class);
    }
}

if (! function_exists('lheRegistryTestNs')) {
    function lheRegistryTestNs(): string
    {
        return 'LEXO\\LaravelHealthEndpoints\\Tests\\Fixtures\\HealthChecks';
    }
}

it('discovers checks in the configured absolute directory', function () {
    config([
        'laravel-health-endpoints.discover.path' => __DIR__.'/../Fixtures/HealthChecks',
        'laravel-health-endpoints.discover.namespace' => lheRegistryTestNs(),
    ]);

    expect(lheRegistryTestRegistry()->all())->toHaveKeys(['disk-space', 'nested'])
        ->not->toHaveKey('abstract-fixture')
        ->not->toHaveKey('not-a');
});

it('resolves relative discover paths against base_path', function () {
    app()->setBasePath((string) realpath(__DIR__.'/../..'));
    config([
        'laravel-health-endpoints.discover.path' => 'tests/Fixtures/HealthChecks',
        'laravel-health-endpoints.discover.namespace' => lheRegistryTestNs(),
    ]);

    expect(lheRegistryTestRegistry()->all())->toHaveKeys(['disk-space', 'nested']);
});

it('does not discover when disabled', function () {
    config([
        'laravel-health-endpoints.discover.enabled' => 'false',
        'laravel-health-endpoints.discover.path' => __DIR__.'/../Fixtures/HealthChecks',
        'laravel-health-endpoints.discover.namespace' => lheRegistryTestNs(),
    ]);

    expect(lheRegistryTestRegistry()->all())->toBe([]);
});

it('discovery skips abstract, interface, trait, non-check and unloadable classes', function () {
    $found = (new HealthCheckDiscovery)->discover(__DIR__.'/../Fixtures/HealthChecks', lheRegistryTestNs());

    expect($found)->toBe([DiskSpaceCheck::class, lheRegistryTestNs().'\\Nested\\NestedCheck']);
});

it('discovery returns empty for a missing directory', function () {
    expect((new HealthCheckDiscovery)->discover('/nonexistent/dir', 'X'))->toBe([]);
});

it('registers from config and facade', function () {
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);
    LaravelHealthEndpoints::register(FacadeFixtureCheck::class);

    expect(lheRegistryTestRegistry()->all())->toHaveKeys(['config-fixture', 'facade-fixture']);
    expect(LaravelHealthEndpoints::find('facade-fixture'))->toBeInstanceOf(FacadeFixtureCheck::class);
});

it('accepts check instances and ignores the same class registered twice', function () {
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);
    lheRegistryTestRegistry()->register(ConfigFixtureCheck::class);
    lheRegistryTestRegistry()->register(new FacadeFixtureCheck);

    expect(array_keys(lheRegistryTestRegistry()->all()))->toBe(['config-fixture', 'facade-fixture']);
});

it('rejects reserved names', function () {
    lheRegistryTestRegistry()->register(ReservedRedisCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'reserved');

it('rejects duplicates', function () {
    lheRegistryTestRegistry()->register(DupACheck::class);
    lheRegistryTestRegistry()->register(DupBCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'already registered');

it('rejects invalid names including overridden ones', function () {
    lheRegistryTestRegistry()->register(BadNameCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, '[a-z0-9-]+');

it('names the offending class in the message', function () {
    lheRegistryTestRegistry()->register(BadNameCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, BadNameCheck::class);

it('rejects custom paths that collide with built-in names', function () {
    lheRegistryTestRegistry()->register(CollidingPathCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'collides');

it('rejects classes that are not health checks', function () {
    config(['laravel-health-endpoints.checks' => [stdClass::class]]);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'not a health check');

it('exposes valid custom routes', function () {
    lheRegistryTestRegistry()->register(CustomPathCheck::class);

    expect(lheRegistryTestRegistry()->customRoutes())->toBe(['billing/stripe' => 'custom-path']);
});

it('registers enabled built-ins, omits disabled ones and skips missing classes', function () {
    $map = [
        'redis' => [StubRedisCheck::class, 'redis.enabled'],
        'database' => ['Missing\\NoSuchCheck', 'database.enabled'],
    ];
    $make = fn () => new HealthCheckRegistry(app(), app('config'), new HealthCheckDiscovery, $map);

    config(['laravel-health-endpoints.discover.enabled' => false, 'laravel-health-endpoints.redis.enabled' => 'true']);
    expect($make()->find('redis'))->toBeInstanceOf(StubRedisCheck::class)
        ->and($make()->find('database'))->toBeNull();

    config(['laravel-health-endpoints.redis.enabled' => false]);
    expect($make()->find('redis'))->toBeNull();
});

it('honours config changes made before the first all() call', function () {
    $registry = lheRegistryTestRegistry();
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);

    expect($registry->all())->toHaveKey('config-fixture');
});

it('rejects trailing newlines and numeric names', function (string $class) {
    lheRegistryTestRegistry()->register($class);
    lheRegistryTestRegistry()->all();
})->with([NewlineNameCheck::class, NumericNameCheck::class])->throws(InvalidHealthCheckException::class, 'invalid name');

it('rejects invalid custom paths including trailing newline', function (string $class) {
    lheRegistryTestRegistry()->register($class);
    lheRegistryTestRegistry()->all();
})->with([BadPathCheck::class, NewlinePathCheck::class])->throws(InvalidHealthCheckException::class, 'invalid custom path');

it('rejects a path whose first segment is another check name', function () {
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);
    lheRegistryTestRegistry()->register(OtherNamePathCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'collides');

it('rejects a path whose first segment is the check own name', function () {
    lheRegistryTestRegistry()->register(SelfNamePathCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'collides');

it('rejects duplicate custom paths', function () {
    lheRegistryTestRegistry()->register(SamePathACheck::class);
    lheRegistryTestRegistry()->register(SamePathBCheck::class);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'already registered by');

it('rejects duplicate names across two instances', function () {
    lheRegistryTestRegistry()->register(new DupACheck);
    lheRegistryTestRegistry()->register(new DupBCheck);
    lheRegistryTestRegistry()->all();
})->throws(InvalidHealthCheckException::class, 'already registered');

it('does not instantiate a class-string twice', function () {
    config(['laravel-health-endpoints.checks' => [CountingCheck::class]]);
    lheRegistryTestRegistry()->register(CountingCheck::class);
    lheRegistryTestRegistry()->all();

    expect(CountingCheck::$made)->toBe(1);
});

it('discovery skips autoloadable files with a missing parent or parse error', function () {
    $found = (new HealthCheckDiscovery)->discover(__DIR__.'/../Fixtures/Broken', 'LEXO\\LaravelHealthEndpoints\\Tests\\Fixtures\\Broken');

    expect($found)->toBe([]);
});

it('memoizes all()', function () {
    $first = lheRegistryTestRegistry()->all();
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);

    expect(lheRegistryTestRegistry()->all())->toBe($first);
});

it('exposes the facade and reserved list', function () {
    expect(LaravelHealthEndpoints::getFacadeRoot())->toBeInstanceOf(HealthCheckRegistry::class)
        ->and(HealthCheckRegistry::RESERVED)->toHaveCount(9);
});
