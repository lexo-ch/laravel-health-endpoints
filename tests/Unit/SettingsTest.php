<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Support\Settings;

it('parses sloppy csv', function () {
    config(['laravel-health-endpoints.database.connections' => ' mysql , reporting ,,mysql']);
    expect(app(Settings::class)->list('database.connections'))->toBe(['mysql', 'reporting']);
});

it('returns empty list for non-string values', function () {
    config(['laravel-health-endpoints.database.connections' => null]);
    expect(app(Settings::class)->list('database.connections'))->toBe([]);
});

it('falls back on invalid ints', function () {
    config(['laravel-health-endpoints.rate_limit' => 'abc', 'laravel-health-endpoints.cache_ttl' => '']);
    expect(app(Settings::class))->rateLimit()->toBe(60)->cacheTtl()->toBe(10);
});

it('enforces minimum on ints', function () {
    config(['laravel-health-endpoints.timeout' => '0']);
    expect(app(Settings::class)->timeout())->toBe(3);
    config(['laravel-health-endpoints.cache_ttl' => '0']);
    expect(app(Settings::class)->cacheTtl())->toBe(0);
});

it('reads config on every call', function () {
    $s = app(Settings::class);
    config(['laravel-health-endpoints.rate_limit' => 5]);
    expect($s->rateLimit())->toBe(5);
    config(['laravel-health-endpoints.rate_limit' => 7]);
    expect($s->rateLimit())->toBe(7);
});

it('is a singleton', fn () => expect(app(Settings::class))->toBe(app(Settings::class)));

it('exposes defaults', function () {
    config(['laravel-health-endpoints.cache_store' => '', 'laravel-health-endpoints.heartbeat_store' => '']);
    $s = app(Settings::class);
    expect($s->cacheStore())->toBe('file')->and($s->heartbeatStore())->toBeNull()->and($s->token())->toBeNull();
});

it('detects protection', function () {
    config(['laravel-health-endpoints.token' => '', 'laravel-health-endpoints.allowed_ips' => '']);
    expect(app(Settings::class)->isProtected())->toBeFalse();
    config(['laravel-health-endpoints.allowed_ips' => '10.0.0.0/8, ']);
    expect(app(Settings::class)->isProtected())->toBeTrue()->and(app(Settings::class)->allowedIps())->toBe(['10.0.0.0/8']);
    config(['laravel-health-endpoints.allowed_ips' => '', 'laravel-health-endpoints.token' => 'x']);
    expect(app(Settings::class)->isProtected())->toBeTrue()->and(app(Settings::class)->token())->toBe('x');
});

it('resolves details mode', function (string $mode, ?string $token, bool $expected) {
    config(['laravel-health-endpoints.details' => $mode, 'laravel-health-endpoints.token' => $token ?? '']);
    expect(app(Settings::class)->detailed())->toBe($expected);
})->with([['auto', null, false], ['AUTO', 'secret', true], ['never', 'secret', false], ['always', null, true], ['bogus', null, false]]);

it('requires a positive rate limit', function (mixed $value, int $expected) {
    config(['laravel-health-endpoints.rate_limit' => $value]);
    expect(app(Settings::class)->rateLimit())->toBe($expected);
})->with([[0, 60], ['0', 60], [-5, 60], ['1', 1], [120, 120]]);

it('reads app info settings', function (mixed $enabled, bool $expected) {
    config(['laravel-health-endpoints.app_info.enabled' => $enabled]);
    expect(app(Settings::class)->appInfoEnabled())->toBe($expected);
})->with([[true, true], [false, false], ['false', false], ['0', false], ['TRUE', true], [null, true], ['', true], ['bogus', true]]);

it('reads the app version', function () {
    config(['laravel-health-endpoints.app_info.version' => ' 2.4.1 ']);
    expect(app(Settings::class)->appVersion())->toBe('2.4.1');
    config(['laravel-health-endpoints.app_info.version' => null]);
    expect(app(Settings::class)->appVersion())->toBeNull();
});

it('reads heartbeat max ages with defaults and a minimum of one', function () {
    expect(app(Settings::class))->queueMaxAge()->toBe(5)->schedulerMaxAge()->toBe(2);

    config(['laravel-health-endpoints.queue.max_age' => '10', 'laravel-health-endpoints.scheduler.max_age' => 0]);
    expect(app(Settings::class))->queueMaxAge()->toBe(10)->schedulerMaxAge()->toBe(2);
});

it('accepts PHP arrays for list settings', function () {
    config(['laravel-health-endpoints.database.connections' => [' mysql ', 'reporting', '', 'mysql', 5, null, ['x']]]);
    expect(app(Settings::class)->list('database.connections'))->toBe(['mysql', 'reporting']);

    config(['laravel-health-endpoints.allowed_ips' => ['10.0.0.1']]);
    expect(app(Settings::class)->allowedIps())->toBe(['10.0.0.1'])
        ->and(app(Settings::class)->isProtected())->toBeTrue();
});

it('fails closed on a non-string token', function (mixed $token) {
    config(['laravel-health-endpoints.token' => $token, 'laravel-health-endpoints.allowed_ips' => '']);
    $s = app(Settings::class);

    expect($s->token())->toBeNull()
        ->and($s->tokenMisconfigured())->toBeTrue()
        ->and($s->isProtected())->toBeTrue()
        ->and($s->detailed())->toBeTrue();
})->with([
    'array' => [['x']],
    'int' => [12345],
    'object' => [new stdClass],
    'true' => [true],
]);

it('treats empty non-string tokens as no token', function (mixed $token) {
    config(['laravel-health-endpoints.token' => $token, 'laravel-health-endpoints.allowed_ips' => '']);
    $s = app(Settings::class);

    expect($s->token())->toBeNull()->and($s->tokenMisconfigured())->toBeFalse()->and($s->isProtected())->toBeFalse();
})->with(['null' => [null], 'empty array' => [[]], 'false' => [false], 'empty string' => ['  ']]);

it('reads enabled flags with a true default', function (mixed $value, bool $expected) {
    config(['laravel-health-endpoints.queue.enabled' => $value]);

    expect(app(Settings::class)->checkEnabled('queue.enabled'))->toBe($expected);
})->with([
    [true, true], [false, false], ['false', false], ['off', false], ['0', false], [' FALSE ', false], [0, false],
    ['yes', true], ['garbage', true], [null, true], ['', true], ['  ', true],
]);

it('never disables a check through a blank env var', function () {
    config(['laravel-health-endpoints.debug_mode.enabled' => '']);
    expect(app(Settings::class)->checkEnabled('debug_mode.enabled'))->toBeTrue();

    config(['laravel-health-endpoints' => array_diff_key(config('laravel-health-endpoints'), ['debug_mode' => true])]);
    expect(app(Settings::class)->checkEnabled('debug_mode.enabled'))->toBeTrue();
});
