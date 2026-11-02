<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckRecovered;
use LEXO\LaravelHealthEndpoints\Runner\StateTracker;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

beforeEach(function () {
    config(['laravel-health-endpoints.cache_store' => 'array']);
});

it('logs one error per outage and info with llm on recovery', function () {
    Log::spy();
    Event::fake();
    $t = app(StateTracker::class);
    $t->record('redis', 'default', CheckResult::fail('down'), 1.0);
    $t->record('redis', 'default', CheckResult::fail('down'), 1.0);
    $t->record('redis', 'default', CheckResult::ok(), 1.0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => $m === '[LHE] redis:default check failed: down');
    Log::shouldHaveReceived('info')->once()->withArgs(fn ($m, $c) => $m === '[LHE] redis:default check recovered' && $c['llm']['alert'] === true);
    Event::assertDispatchedTimes(HealthCheckFailed::class, 1);
    Event::assertDispatchedTimes(HealthCheckRecovered::class, 1);
});

it('uses a label without target for plain checks', function () {
    Log::spy();
    app(StateTracker::class)->record('disk', null, CheckResult::fail('full'), 1.0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => $m === '[LHE] disk check failed: full');
});

it('does not log or dispatch while healthy', function () {
    Log::spy();
    Event::fake();
    app(StateTracker::class)->record('redis', null, CheckResult::ok(), 1.0);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('info');
    Event::assertNotDispatched(HealthCheckFailed::class);
    Event::assertNotDispatched(HealthCheckRecovered::class);
});

it('adds llm channel and priority to failures', function () {
    config(['laravel-health-endpoints.log.llm_channel' => 'monitoring', 'laravel-health-endpoints.log.llm_priority' => 'urgent']);
    Log::spy();
    app(StateTracker::class)->record('redis', null, CheckResult::fail('down'), 1.0, new RuntimeException('raw'));
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m, $c) => $c['llm'] === ['channel' => 'monitoring', 'priority' => 'urgent']
        && $c['exception'] instanceof RuntimeException
        && array_keys($c) === ['check', 'target', 'message', 'meta', 'duration_ms', 'exception', 'llm']);
});

it('adds the llm channel to recoveries without priority', function () {
    config(['laravel-health-endpoints.log.llm_channel' => 'monitoring', 'laravel-health-endpoints.log.llm_priority' => 'urgent']);
    Log::spy();
    $t = app(StateTracker::class);
    $t->record('redis', null, CheckResult::fail('down'), 1.0);
    $t->record('redis', null, CheckResult::ok(), 1.0);
    Log::shouldHaveReceived('info')->once()->withArgs(fn ($m, $c) => $c['llm'] === ['alert' => true, 'channel' => 'monitoring']);
});

it('re-logs still failing checks after remind_minutes without an event', function () {
    config(['laravel-health-endpoints.log.remind_minutes' => 5]);
    Log::spy();
    Event::fake();
    $t = app(StateTracker::class);
    $t->record('redis', null, CheckResult::fail('down'), 1.0);
    $this->travel(4)->minutes();
    $t->record('redis', null, CheckResult::fail('down'), 1.0);
    Log::shouldHaveReceived('error')->once();
    $this->travel(2)->minutes();
    $t->record('redis', null, CheckResult::fail('down'), 1.0);
    Log::shouldHaveReceived('error')->twice();
    Event::assertDispatchedTimes(HealthCheckFailed::class, 1);
});

it('still logs and dispatches when the state store fails', function () {
    Event::fake([HealthCheckFailed::class]);
    Cache::shouldReceive('store')->atLeast()->once()->andThrow(new RuntimeException('store broke'));
    Log::spy();
    app(StateTracker::class)->record('redis', null, CheckResult::fail('down'), 1.0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => $m === '[LHE] redis check failed: down');
    Event::assertDispatchedTimes(HealthCheckFailed::class, 1);
});

it('still dispatches the event when logging fails', function () {
    Log::shouldReceive('error')->once()->andThrow(new RuntimeException('log broke'));
    Event::fake([HealthCheckFailed::class]);
    app(StateTracker::class)->record('redis', null, CheckResult::fail('down'), 1.0);
    Event::assertDispatchedTimes(HealthCheckFailed::class, 1);
});

it('still logs when a listener fails', function () {
    $called = 0;
    Event::listen(HealthCheckFailed::class, function () use (&$called) {
        $called++;

        throw new RuntimeException('listener broke');
    });
    Log::spy();
    app(StateTracker::class)->record('redis', null, CheckResult::fail('down'), 1.0);
    expect($called)->toBe(1);
    Log::shouldHaveReceived('error')->once();
});
