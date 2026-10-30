<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Exceptions\InvalidHealthCheckException;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckDiscovery;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\HealthChecks\DiskSpaceCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\BadNameCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\CollidingPathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\DupACheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\DupBCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid\ReservedRedisCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\ConfigFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\CustomPathCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\FacadeFixtureCheck;

const FIXTURE_NS = 'LEXO\\LaravelHealthEndpoints\\Tests\\Fixtures\\HealthChecks';

function registry(): HealthCheckRegistry
{
    return app(HealthCheckRegistry::class);
}

it('discovers checks in the configured absolute directory', function () {
    config([
        'laravel-health-endpoints.discover.path' => __DIR__.'/../Fixtures/HealthChecks',
        'laravel-health-endpoints.discover.namespace' => FIXTURE_NS,
    ]);

    expect(registry()->all())->toHaveKeys(['disk-space', 'nested'])
        ->not->toHaveKey('abstract-fixture')
        ->not->toHaveKey('not-a');
});

it('resolves relative discover paths against base_path', function () {
    config([
        'laravel-health-endpoints.discover.path' => 'does/not/exist',
        'laravel-health-endpoints.discover.namespace' => FIXTURE_NS,
    ]);

    expect(registry()->all())->toBe([]);
});

it('does not discover when disabled', function () {
    config([
        'laravel-health-endpoints.discover.enabled' => 'false',
        'laravel-health-endpoints.discover.path' => __DIR__.'/../Fixtures/HealthChecks',
        'laravel-health-endpoints.discover.namespace' => FIXTURE_NS,
    ]);

    expect(registry()->all())->toBe([]);
});

it('discovery skips abstract, interface, trait, non-check and unloadable classes', function () {
    $found = (new HealthCheckDiscovery)->discover(__DIR__.'/../Fixtures/HealthChecks', FIXTURE_NS);

    expect($found)->toBe([DiskSpaceCheck::class, FIXTURE_NS.'\\Nested\\NestedCheck']);
});

it('discovery skips files whose class cannot be autoloaded', function () {
    $dir = sys_get_temp_dir().'/lhe-disc-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Missing.php', "<?php\nnamespace LheTmp;\nfinal class Other {}\n");
    file_put_contents($dir.'/Broken.php', "<?php\nnamespace LheTmp;\nfinal class Broken {");

    try {
        expect((new HealthCheckDiscovery)->discover($dir, 'LheTmp'))->toBe([]);
    } finally {
        array_map('unlink', glob($dir.'/*.php'));
        rmdir($dir);
    }
});

it('discovery returns empty for a missing directory', function () {
    expect((new HealthCheckDiscovery)->discover('/nonexistent/dir', 'X'))->toBe([]);
});

it('registers from config and facade', function () {
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);
    LaravelHealthEndpoints::register(FacadeFixtureCheck::class);

    expect(registry()->all())->toHaveKeys(['config-fixture', 'facade-fixture']);
    expect(LaravelHealthEndpoints::find('facade-fixture'))->toBeInstanceOf(FacadeFixtureCheck::class);
});

it('accepts check instances and ignores the same class registered twice', function () {
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);
    registry()->register(ConfigFixtureCheck::class);
    registry()->register(new FacadeFixtureCheck);

    expect(array_keys(registry()->all()))->toBe(['config-fixture', 'facade-fixture']);
});

it('rejects reserved names', function () {
    registry()->register(ReservedRedisCheck::class);
    registry()->all();
})->throws(InvalidHealthCheckException::class, 'reserved');

it('rejects duplicates', function () {
    registry()->register(DupACheck::class);
    registry()->register(DupBCheck::class);
    registry()->all();
})->throws(InvalidHealthCheckException::class, 'already registered');

it('rejects invalid names including overridden ones', function () {
    registry()->register(BadNameCheck::class);
    registry()->all();
})->throws(InvalidHealthCheckException::class, '[a-z0-9-]+');

it('names the offending class in the message', function () {
    registry()->register(BadNameCheck::class);
    registry()->all();
})->throws(InvalidHealthCheckException::class, BadNameCheck::class);

it('rejects custom paths that collide with built-in names', function () {
    registry()->register(CollidingPathCheck::class);
    registry()->all();
})->throws(InvalidHealthCheckException::class, 'collides');

it('rejects classes that are not health checks', function () {
    config(['laravel-health-endpoints.checks' => [stdClass::class]]);
    registry()->all();
})->throws(InvalidHealthCheckException::class, 'not a health check');

it('exposes valid custom routes', function () {
    registry()->register(CustomPathCheck::class);

    expect(registry()->customRoutes())->toBe(['billing/stripe' => 'custom-path']);
});

it('omits disabled built-ins and tolerates missing built-in classes', function () {
    config(['laravel-health-endpoints.redis.enabled' => false]);

    expect(registry()->find('redis'))->toBeNull();
});

it('memoizes all()', function () {
    $first = registry()->all();
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);

    expect(registry()->all())->toBe($first);
});

it('exposes the facade and reserved list', function () {
    expect(LaravelHealthEndpoints::getFacadeRoot())->toBeInstanceOf(HealthCheckRegistry::class)
        ->and(HealthCheckRegistry::RESERVED)->toHaveCount(9);
});
