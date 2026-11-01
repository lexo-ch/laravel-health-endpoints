<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.storage.enabled' => true,
        'filesystems.default' => 'local',
    ]);
    Cache::store('array')->flush();
    lheReloadRoutes();
});

it('passes on a fake disk and leaves no files', function () {
    Storage::fake('local');

    $this->getJson('/lhe/v1/storage')->assertOk()
        ->assertJsonPath('checks.storage.status', 'ok')
        ->assertJsonPath('checks.storage.targets.local.status', 'ok');

    expect(Storage::disk('local')->allFiles('.lhe-health'))->toBe([]);
});

it('checks several configured disks', function () {
    Storage::fake('local');
    Storage::fake('other');
    config(['filesystems.disks.other' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/other')]]);
    config(['laravel-health-endpoints.storage.disks' => 'local,other']);

    $this->getJson('/lhe/v1/storage')->assertOk()
        ->assertJsonPath('checks.storage.targets.other.status', 'ok');
});

it('fails an unconfigured disk', function () {
    config(['laravel-health-endpoints.storage.disks' => 'ghost']);

    $this->getJson('/lhe/v1/storage/ghost')->assertStatus(503)
        ->assertJsonPath('checks.storage.targets.ghost.message', 'Disk not configured');
});

it('deletes the probe file when reading fails', function () {
    Storage::fake('local');
    $written = null;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path) use (&$written) {
        $written = $path;

        return true;
    });
    $disk->shouldReceive('get')->once()->andThrow(new RuntimeException('boom /secret/path'));
    $deleted = null;
    $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$deleted) {
        $deleted = $path;

        return true;
    });
    Storage::set('local', $disk);

    $this->getJson('/lhe/v1/storage/local')->assertStatus(503)
        ->assertJsonMissing(['boom /secret/path']);

    expect($deleted)->toBe($written)
        ->and($written)->toMatch('#^\.lhe-health/[A-Za-z0-9]{32}\.txt$#');
});

it('deletes the probe file on read-back mismatch', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturn(true);
    $disk->shouldReceive('get')->once()->andReturn('different');
    $disk->shouldReceive('delete')->once();
    Storage::set('local', $disk);

    $this->getJson('/lhe/v1/storage/local')->assertStatus(503)
        ->assertJsonPath('checks.storage.targets.local.message', 'Read-back mismatch');
});
