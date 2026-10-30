<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.details' => 'always',
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

it('writes the heartbeat when the scheduled event runs', function () {
    lheScheduleNames();
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'lhe:scheduler-heartbeat');

    expect(app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY))->toBeNull();
    $event->run(app());
    expect(app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY))->not->toBeNull();
});
