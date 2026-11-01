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

it('requires a positive rate limit', function (mixed $value, int $expected) {
    config(['laravel-health-endpoints.rate_limit' => $value]);
    expect(app(Settings::class)->rateLimit())->toBe($expected);
})->with([[0, 60], ['0', 60], [-5, 60], ['1', 1], [120, 120]]);

it('reads the show version setting', function (mixed $enabled, bool $expected) {
    config(['laravel-health-endpoints.show_version' => $enabled]);
    expect(app(Settings::class)->showVersion())->toBe($expected);
})->with([[true, true], [false, false], ['false', false], ['0', false], ['TRUE', true], [null, true], ['', true], ['bogus', true]]);

it('reads the app version from composer.json', function () {
    lheUseComposerJson('{"name": "acme/app", "version": "1.21.0"}');

    expect(new Settings(app('config')))->appVersion()->toBe('1.21.0');
});

it('ignores a missing, invalid or unsafe composer.json version', function (?string $contents) {
    lheUseComposerJson($contents);

    expect(new Settings(app('config')))->appVersion()->toBeNull();
})->with([
    'no file' => [null],
    'no version key' => ['{"name": "acme/app"}'],
    'broken json' => ['{"version": '],
    'json list' => ['["1.0.0"]'],
    'non-string' => ['{"version": 1}'],
    'empty' => ['{"version": ""}'],
    'markup' => ['{"version": "<script>alert(1)</script>"}'],
    'whitespace' => ['{"version": "1.0 beta"}'],
    'too long' => ['{"version": "'.str_repeat('1', 65).'"}'],
    'oversized file' => ['{"version": "1.0.0", "x": "'.str_repeat('a', 1_048_576).'"}'],
]);

it('reads composer.json only once per instance', function () {
    lheUseComposerJson('{"version": "1.0.0"}');
    $settings = new Settings(app('config'));

    expect($settings->appVersion())->toBe('1.0.0');
    file_put_contents(base_path('composer.json'), '{"version": "9.9.9"}');
    expect($settings->appVersion())->toBe('1.0.0');
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
        ->and($s->isProtected())->toBeTrue();
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
