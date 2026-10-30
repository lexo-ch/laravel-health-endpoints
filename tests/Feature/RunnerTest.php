<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed;
use LEXO\LaravelHealthEndpoints\Exceptions\TargetNotFoundException;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Support\CheckReport;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\HealthReport;
use LEXO\LaravelHealthEndpoints\Support\Status;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\FailingFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\ThrowingFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\TwoTargetFixture;
use RuntimeException;

beforeEach(function () {
    config(['laravel-health-endpoints.cache_store' => 'array']);
});

it('measures and catches exceptions', function () {
    $report = app(HealthRunner::class)->runCheck(new ThrowingFixtureCheck('connection refused to redis://u:p@h'));
    expect($report)->status->toBe(Status::Fail)->message->toBe('Connection refused')->durationMs->toBeGreaterThan(0);
});

it('sanitizes returned fail messages', fn () => expect(app(HealthRunner::class)->runCheck(new FailingFixtureCheck('password=x'))->message)->toBe('password=***'));

it('rejects unknown targets', fn () => app(HealthRunner::class)->runCheck(new TwoTargetFixture(['a' => true]), 'zzz'))
    ->throws(TargetNotFoundException::class);

it('rejects a target on a check without targets', fn () => app(HealthRunner::class)->runCheck(new OkFixtureCheck, 'a'))
    ->throws(TargetNotFoundException::class);

it('aggregates to 503 when any check fails', fn () => expect(app(HealthRunner::class)->runAll([new OkFixtureCheck, new FailingFixtureCheck('x')])->httpStatus())->toBe(503));

it('aggregates to 200 when all pass', fn () => expect(app(HealthRunner::class)->runAll([new OkFixtureCheck])->httpStatus())->toBe(200));

it('renders minimal output without details', function () {
    $arr = app(HealthRunner::class)->runAll([new TwoTargetFixture(['a' => false])])->toArray(false, ['name' => 'X']);
    expect($arr)->toHaveKeys(['status', 'timestamp', 'checks'])->not->toHaveKeys(['app', 'duration_ms', 'cached'])
        ->and($arr['checks']['two-target-fixture'])->toBe(['status' => 'fail']);
});

it('renders detailed output with targets, messages and app', function () {
    $arr = app(HealthRunner::class)->runAll([new TwoTargetFixture(['a' => true, 'b' => false]), new OkFixtureCheck])
        ->toArray(true, ['name' => 'X']);
    expect($arr)->toHaveKeys(['status', 'timestamp', 'duration_ms', 'cached', 'app', 'checks'])
        ->and($arr['status'])->toBe('fail')->and($arr['cached'])->toBeFalse()->and($arr['duration_ms'])->toBeInt()
        ->and($arr['checks']['two-target-fixture']['targets']['a']['status'])->toBe('ok')
        ->and($arr['checks']['two-target-fixture']['targets']['b']['message'])->toBe('Target failed')
        ->and($arr['checks']['two-target-fixture'])->not->toHaveKey('message')
        ->and($arr['checks']['ok-fixture'])->toMatchArray(['message' => 'fine', 'meta' => ['k' => 'v']])
        ->and($arr['checks']['ok-fixture'])->not->toHaveKey('targets');
    expect(app(HealthRunner::class)->runAll([new OkFixtureCheck])->toArray(true, null))->not->toHaveKey('app');
});

it('runs a single target inside the parent shape', function () {
    $r = app(HealthRunner::class)->runCheck(new TwoTargetFixture(['a' => true, 'b' => false]), 'a');
    expect($r->status)->toBe(Status::Ok)->and(array_keys($r->targets ?? []))->toBe(['a']);
});

it('reports empty targets as fail', function () {
    $r = app(HealthRunner::class)->runCheck(new TwoTargetFixture([]));
    expect($r->status)->toBe(Status::Fail)->and($r->message)->toBe('No targets configured');
});

it('catches a throwing target without stopping the others', function () {
    $check = new class extends TargetedCheck
    {
        public function name(): string
        {
            return 'multi';
        }

        public function targets(): array
        {
            return ['x', 'y'];
        }

        public function runTarget(string $target): CheckResult
        {
            return $target === 'x' ? throw new RuntimeException('connection refused') : CheckResult::ok();
        }
    };
    $r = app(HealthRunner::class)->runCheck($check);
    expect($r->status)->toBe(Status::Fail)->and($r->message)->toBeNull()
        ->and($r->targets['x']->message)->toBe('Connection refused')
        ->and($r->targets['y']->status)->toBe(Status::Ok);
});

it('records state per target and logs without leaking the raw exception message', function () {
    Log::spy();
    Event::fake();
    app(HealthRunner::class)->runCheck(new TwoTargetFixture(['a' => false]));
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => $m === '[LHE] two-target-fixture:a check failed: Target failed');
    Event::assertDispatched(HealthCheckFailed::class, fn ($e) => $e->target === 'a' && $e->check === 'two-target-fixture');
});

it('never puts raw exception text into the response', function () {
    Log::spy();
    $r = app(HealthRunner::class)->runCheck(new ThrowingFixtureCheck('SQLSTATE host=/var/www/app/secret.php password=hunter2'));
    expect(json_encode($r->toArray(true)))->not->toContain('hunter2')->not->toContain('/var/www');
});

it('round-trips reports through the cache array', function () {
    $report = app(HealthRunner::class)->runAll([new TwoTargetFixture(['a' => true, 'b' => false]), new OkFixtureCheck]);
    $copy = HealthReport::fromCacheArray(json_decode(json_encode($report->toCacheArray(), JSON_THROW_ON_ERROR), true));
    expect($copy->cached)->toBeTrue()
        ->and($copy->status())->toBe(Status::Fail)
        ->and($copy->timestamp->equalTo($report->timestamp))->toBeTrue()
        ->and($copy->durationMs)->toBe($report->durationMs)
        ->and($copy->checks['two-target-fixture'])->toEqual($report->checks['two-target-fixture'])
        ->and($copy->checks['ok-fixture'])->toEqual($report->checks['ok-fixture'])
        ->and($copy->checks['ok-fixture'])->toBeInstanceOf(CheckReport::class);
});
