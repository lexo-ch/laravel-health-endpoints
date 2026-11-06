<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.scheduler.enabled' => true,
        'cache.default' => 'array',
    ]);
    Cache::store('array')->flush();
    lheReloadRoutes();
});

function lheScheduleNames(): array
{
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();
}

it('fails when no heartbeat exists', function () {
    $this->getJson('/lhe/v1/scheduler')->assertStatus(503)
        ->assertJsonPath('checks.scheduler.message', 'No scheduler heartbeat yet — is schedule:run / schedule:work running?');
});

it('passes with a fresh heartbeat and exposes it in meta', function () {
    app(Heartbeats::class)->touch(Heartbeats::SCHEDULER_KEY);

    $this->getJson('/lhe/v1/scheduler')->assertOk()
        ->assertJsonPath('checks.scheduler.status', 'ok')
        ->assertJsonPath('checks.scheduler.meta.last_heartbeat', now()->toIso8601String());
});

it('reads stored heartbeats as UTC timestamps', function (int|string $stored) {
    Cache::store('array')->forever(Heartbeats::SCHEDULER_KEY, $stored);

    $last = app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY);

    expect($last?->getTimestamp())->toBe(1_700_000_000)
        ->and($last?->getOffset())->toBe(0);
})->with([1_700_000_000, '1700000000']);

it('ignores non-numeric heartbeat values', function (mixed $stored) {
    Cache::store('array')->forever(Heartbeats::SCHEDULER_KEY, $stored);

    expect(app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY))->toBeNull();
})->with(['abc', '-1', '1.5', [[1]], 1.5]);

it('reads heartbeats without Carbon rawCreateFromFormat', function () {
    // Carbon consults the test clock inside rawCreateFromFormat(), so the closure sees that call in its backtrace.
    $rawCalled = false;
    Cache::store('array')->forever(Heartbeats::SCHEDULER_KEY, 1_700_000_000);
    CarbonImmutable::setTestNow(function () use (&$rawCalled) {
        $frames = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        $rawCalled = $rawCalled || in_array('rawCreateFromFormat', $frames, true);

        return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    });

    try {
        app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY);
    } finally {
        CarbonImmutable::setTestNow();
    }

    expect($rawCalled)->toBeFalse();
});

it('fails when the heartbeat is too far in the future', function (int $ahead) {
    $this->freezeTime();
    Cache::store('array')->forever(Heartbeats::SCHEDULER_KEY, now()->getTimestamp() + $ahead);

    $this->getJson('/lhe/v1/scheduler')->assertStatus(503)
        ->assertJsonPath('checks.scheduler.message', 'Scheduler heartbeat is in the future — check server clocks');
})->with([61, 86_400]);

it('tolerates small clock skew between servers', function () {
    $this->freezeTime();
    Cache::store('array')->forever(Heartbeats::SCHEDULER_KEY, now()->getTimestamp() + 60);

    $this->getJson('/lhe/v1/scheduler')->assertOk();
});

it('fails on an out-of-range heartbeat value', function () {
    Cache::store('array')->forever(Heartbeats::SCHEDULER_KEY, PHP_INT_MAX);

    $this->getJson('/lhe/v1/scheduler')->assertStatus(503);
});

it('fails when the heartbeat is stale', function () {
    app(Heartbeats::class)->touch(Heartbeats::SCHEDULER_KEY);
    $this->travel(3)->minutes();

    $this->getJson('/lhe/v1/scheduler')->assertStatus(503)
        ->assertJsonPath('checks.scheduler.message', 'Last scheduler heartbeat 3 min ago (max 2)');
});

it('stays ok exactly at the max age', function () {
    app(Heartbeats::class)->touch(Heartbeats::SCHEDULER_KEY);
    $this->travel(2)->minutes();

    $this->getJson('/lhe/v1/scheduler')->assertOk();
});

it('treats a throwing heartbeat store as a missing heartbeat', function () {
    config(['laravel-health-endpoints.heartbeat_store' => 'nonexistent-store']);

    $this->getJson('/lhe/v1/scheduler')->assertStatus(503)
        ->assertJsonPath('checks.scheduler.message', 'No scheduler heartbeat yet — is schedule:run / schedule:work running?');

    app(Heartbeats::class)->touch(Heartbeats::SCHEDULER_KEY);
});

it('registers the scheduler heartbeat event only when enabled', function () {
    expect(lheScheduleNames())->toContain('lhe:scheduler-heartbeat');

    config(['laravel-health-endpoints.scheduler.enabled' => false]);
    expect(lheScheduleNames())->not->toContain('lhe:scheduler-heartbeat');
});

it('does not register heartbeat events outside the console', function () {
    // Apps that inspect Schedule on web requests (e.g. dashboards) must see only their own events.
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), false);

    try {
        expect(lheScheduleNames())->toBe([]);
    } finally {
        $property->setValue(app(), null);
    }
});

it('writes the heartbeat when the scheduled event runs', function () {
    lheScheduleNames();
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'lhe:scheduler-heartbeat');

    expect(app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY))->toBeNull();
    $event->run(app());
    expect(app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY))->not->toBeNull();
});
