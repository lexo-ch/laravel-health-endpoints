<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use LEXO\LaravelHealthEndpoints\Jobs\QueueHeartbeatJob;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.details' => 'always',
        'laravel-health-endpoints.queue.enabled' => true,
        'cache.default' => 'array',
        'queue.default' => 'database',
        'queue.connections.sync' => ['driver' => 'sync'],
        'queue.connections.null' => ['driver' => 'null'],
        'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default'],
    ]);
    Cache::store('array')->flush();
    lheReloadRoutes();
});

function lheQueueEvents(): array
{
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_starts_with((string) $e->description, 'lhe:queue-heartbeat:'))
        ->values()->all();
}

it('is ok for sync and null drivers', function () {
    config(['laravel-health-endpoints.queue.connections' => 'sync,null']);

    $this->getJson('/lhe/v1/queue')->assertOk()
        ->assertJsonPath('checks.queue.targets.sync.message', 'sync driver — no worker required')
        ->assertJsonPath('checks.queue.targets.null.message', 'null driver');
});

it('fails an unconfigured connection', function () {
    config(['laravel-health-endpoints.queue.connections' => 'ghost']);

    $this->getJson('/lhe/v1/queue/ghost')->assertStatus(503)
        ->assertJsonPath('checks.queue.targets.ghost.message', 'Connection not configured');
});

it('fails without a heartbeat on a real driver', function () {
    $this->getJson('/lhe/v1/queue')->assertStatus(503)
        ->assertJsonPath('checks.queue.targets.database.message', 'No queue heartbeat yet — are the scheduler and a queue worker running?');
});

it('passes after the job handled and fails when stale', function () {
    (new QueueHeartbeatJob('database:default'))->handle(app(Heartbeats::class));

    $this->getJson('/lhe/v1/queue')->assertOk()
        ->assertJsonPath('checks.queue.targets.database.meta.last_heartbeat', now()->toIso8601String());

    $this->travel(5)->minutes();
    $this->getJson('/lhe/v1/queue')->assertOk();

    $this->travel(1)->minutes();
    $this->getJson('/lhe/v1/queue')->assertStatus(503)
        ->assertJsonPath('checks.queue.targets.database.message', 'Last queue heartbeat 6 min ago (max 5)');
});

it('shares one heartbeat between "conn" and "conn:default-queue"', function () {
    config(['laravel-health-endpoints.queue.connections' => 'database,database:default']);
    (new QueueHeartbeatJob('database:default'))->handle(app(Heartbeats::class));

    $this->getJson('/lhe/v1/queue')->assertOk();
});

it('tracks heartbeats per queue name', function () {
    config(['laravel-health-endpoints.queue.connections' => 'database:emails']);
    (new QueueHeartbeatJob('database:default'))->handle(app(Heartbeats::class));

    $this->getJson('/lhe/v1/queue')->assertStatus(503);

    (new QueueHeartbeatJob('database:emails'))->handle(app(Heartbeats::class));
    $this->getJson('/lhe/v1/queue')->assertOk();
});

it('treats a throwing heartbeat store as a missing heartbeat', function () {
    config(['laravel-health-endpoints.heartbeat_store' => 'nonexistent-store']);

    $this->getJson('/lhe/v1/queue')->assertStatus(503)
        ->assertJsonPath('checks.queue.targets.database.message', 'No queue heartbeat yet — are the scheduler and a queue worker running?');
});

it('dispatches heartbeat jobs to the parsed connection and queue', function () {
    config(['laravel-health-endpoints.queue.connections' => 'database:emails,sync,null,ghost']);
    Queue::fake();

    $events = lheQueueEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->description)->toBe('lhe:queue-heartbeat:database:emails');

    $events[0]->run(app());

    Queue::assertPushedOn('emails', QueueHeartbeatJob::class, fn (QueueHeartbeatJob $job) => $job->target === 'database:emails');
});

it('schedules the default target and skips scheduling when disabled', function () {
    expect(collect(lheQueueEvents())->pluck('description')->all())->toBe(['lhe:queue-heartbeat:database:default']);

    config(['laravel-health-endpoints.queue.enabled' => false]);
    expect(lheQueueEvents())->toBe([]);
});

it('carries only the target string in the job payload', function () {
    $job = new QueueHeartbeatJob('database:default');

    expect(get_object_vars($job))->toHaveKey('target')
        ->and($job->tries)->toBe(1);
});
