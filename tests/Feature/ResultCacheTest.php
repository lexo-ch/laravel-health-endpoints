<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Support\HealthReport;
use LEXO\LaravelHealthEndpoints\Support\ResultCache;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;

beforeEach(function () {
    config(['laravel-health-endpoints.cache_store' => 'array', 'laravel-health-endpoints.cache_ttl' => 10]);
    Cache::store('array')->flush();
});

function lheRun(int &$calls): Closure
{
    return function () use (&$calls): HealthReport {
        $calls++;

        return app(HealthRunner::class)->runAll([new OkFixtureCheck]);
    };
}

it('returns cached report within ttl', function () {
    $calls = 0;
    $cache = app(ResultCache::class);

    $first = $cache->remember('all', lheRun($calls));
    $second = $cache->remember('all', lheRun($calls));

    expect($calls)->toBe(1)->and($first->cached)->toBeFalse()->and($second->cached)->toBeTrue();
});

it('uses separate entries per scope', function () {
    $calls = 0;
    $cache = app(ResultCache::class);

    $cache->remember('all', lheRun($calls));
    $cache->remember('check:database', lheRun($calls));

    expect($calls)->toBe(2);
});

it('expires after ttl', function () {
    $calls = 0;
    $cache = app(ResultCache::class);

    $cache->remember('all', lheRun($calls));
    $this->travel(11)->seconds();
    $cache->remember('all', lheRun($calls));

    expect($calls)->toBe(2);
});

it('ttl 0 disables caching', function () {
    config(['laravel-health-endpoints.cache_ttl' => 0]);
    $calls = 0;
    $cache = app(ResultCache::class);

    $cache->remember('all', lheRun($calls));
    $cache->remember('all', lheRun($calls));

    expect($calls)->toBe(2);
});

it('falls back to running uncached when the store is broken', function () {
    config(['laravel-health-endpoints.cache_store' => 'missing-store']);
    $calls = 0;

    $report = app(ResultCache::class)->remember('all', lheRun($calls));

    expect($calls)->toBe(1)->and($report->cached)->toBeFalse();
});

it('treats a corrupt cached payload as a miss and overwrites it', function () {
    $key = 'lhe:result:'.sha1('all');
    Cache::store('array')->put($key, ['timestamp' => 'garbage', 'checks' => ['x' => 'nope']], 60);
    $calls = 0;
    $cache = app(ResultCache::class);

    $first = $cache->remember('all', lheRun($calls));
    $second = $cache->remember('all', lheRun($calls));

    expect($calls)->toBe(1)->and($first->cached)->toBeFalse()->and($second->cached)->toBeTrue();
});

it('treats a non-array cached payload as a miss', function () {
    Cache::store('array')->put('lhe:result:'.sha1('all'), 'garbage', 60);
    $calls = 0;

    app(ResultCache::class)->remember('all', lheRun($calls));

    expect($calls)->toBe(1);
});

it('does not swallow exceptions from the closure', function () {
    app(ResultCache::class)->remember('all', fn () => throw new RuntimeException('boom'));
})->throws(RuntimeException::class, 'boom');
