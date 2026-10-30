<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.details' => 'always',
        'laravel-health-endpoints.cache.enabled' => true,
    ]);
    Cache::store('array')->flush();
    lheReloadRoutes();
});

it('passes on the array store and leaves no probe key behind', function () {
    config(['cache.default' => 'array']);

    $this->getJson('/lhe/v1/cache')->assertOk()
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonPath('checks.cache.targets.array.status', 'ok');

    $store = Cache::store('array')->getStore();
    $keys = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);
    expect(array_filter(array_keys($keys), fn ($k) => str_contains((string) $k, 'lhe:probe:')))->toBe([]);
});

it('checks several configured stores and supports a single store route', function () {
    config([
        'cache.stores.second' => ['driver' => 'array'],
        'laravel-health-endpoints.cache.stores' => 'array, second',
    ]);

    $this->getJson('/lhe/v1/cache')->assertOk()
        ->assertJsonPath('checks.cache.targets.array.status', 'ok')
        ->assertJsonPath('checks.cache.targets.second.status', 'ok');

    $this->getJson('/lhe/v1/cache/second')->assertOk()
        ->assertJsonMissingPath('checks.cache.targets.array');
});

it('fails an unconfigured store and never touches it', function () {
    config(['laravel-health-endpoints.cache.stores' => 'array,ghost']);

    $this->getJson('/lhe/v1/cache/ghost')->assertStatus(503)
        ->assertJsonPath('checks.cache.targets.ghost.message', 'Store not configured');
});

it('fails on read-back mismatch and still forgets the probe key', function () {
    $store = new class extends ArrayStore
    {
        /** @var list<string> */
        public array $forgotten = [];

        public function get($key)
        {
            return str_starts_with((string) $key, 'lhe:probe:') ? 'something-else' : parent::get($key);
        }

        public function forget($key)
        {
            $this->forgotten[] = (string) $key;

            return parent::forget($key);
        }
    };
    Cache::extend('lhe_bad', fn () => Cache::repository($store));
    config(['cache.stores.bad' => ['driver' => 'lhe_bad'], 'laravel-health-endpoints.cache.stores' => 'bad']);

    $this->getJson('/lhe/v1/cache/bad')->assertStatus(503)
        ->assertJsonPath('checks.cache.targets.bad.message', 'Read-back mismatch');

    expect($store->forgotten)->toHaveCount(1)
        ->and($store->forgotten[0])->toStartWith('lhe:probe:');
});
