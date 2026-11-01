<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckRecovered;
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

it('renders output with targets and messages', function () {
    $arr = app(HealthRunner::class)->runAll([new TwoTargetFixture(['a' => true, 'b' => false]), new OkFixtureCheck])
        ->toArray(true);
    expect(array_keys($arr))->toBe(['status', 'timestamp', 'duration_ms', 'cached', 'checks'])
        ->and($arr['status'])->toBe('fail')->and($arr['cached'])->toBeFalse()->and($arr['duration_ms'])->toBeInt()
        ->and($arr['checks']['two-target-fixture']['targets']['a']['status'])->toBe('ok')
        ->and($arr['checks']['two-target-fixture']['targets']['b']['message'])->toBe('Target failed')
        ->and($arr['checks']['two-target-fixture'])->not->toHaveKey('message')
        ->and($arr['checks']['ok-fixture'])->toMatchArray(['message' => 'fine', 'meta' => ['k' => 'v']])
        ->and($arr['checks']['ok-fixture'])->not->toHaveKey('targets');
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

it('records state per target and logs failing targets', function () {
    Log::spy();
    Event::fake();
    app(HealthRunner::class)->runCheck(new TwoTargetFixture(['a' => false]));
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => $m === '[LHE] two-target-fixture:a check failed: Target failed');
    Event::assertDispatched(HealthCheckFailed::class, fn ($e) => $e->target === 'a' && $e->check === 'two-target-fixture');
});

it('never puts raw exception text into the response', function () {
    Log::spy();
    Log::spy();
    $r = app(HealthRunner::class)->runCheck(new ThrowingFixtureCheck('SQLSTATE host=/var/www/app/secret.php password=hunter2'));
    expect(json_encode($r->toArray(true)))->not->toContain('hunter2')->not->toContain('/var/www');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m, $c) => $c['exception'] instanceof RuntimeException && ! str_contains($m, 'hunter2'));
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

it('survives a throwing targets() and keeps running other checks', function () {
    Log::spy();
    $bad = new class extends TargetedCheck
    {
        public function name(): string
        {
            return 'bad-targets';
        }

        public function targets(): array
        {
            throw new RuntimeException('timeout talking to db password=x');
        }

        public function runTarget(string $target): CheckResult
        {
            return CheckResult::ok();
        }
    };
    $report = app(HealthRunner::class)->runAll([$bad, new OkFixtureCheck]);
    expect($report->checks['bad-targets'])->status->toBe(Status::Fail)->message->toBe('Timeout')->targets->toBeNull()
        ->and($report->checks['ok-fixture']->status)->toBe(Status::Ok)
        ->and($report->httpStatus())->toBe(503);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m, $c) => $m === '[LHE] bad-targets check failed: Timeout' && $c['exception'] instanceof RuntimeException);
});

it('sanitizes string values in meta recursively', function () {
    $check = new class extends Check
    {
        public function run(): CheckResult
        {
            return CheckResult::ok(null, ['dsn' => 'password=x', 'n' => 3, 'nested' => ['a' => 'redis://u:p@h']]);
        }
    };
    $r = app(HealthRunner::class)->runCheck($check);
    expect($r->meta)->toBe(['dsn' => 'password=***', 'n' => 3, 'nested' => ['a' => 'redis://***@h']]);
});

it('turns an empty ok message into null', function () {
    $check = new class extends Check
    {
        public function run(): CheckResult
        {
            return CheckResult::ok("\n");
        }
    };
    expect(app(HealthRunner::class)->runCheck($check)->message)->toBeNull();
});

it('rejects corrupt cache data', function (array $data) {
    HealthReport::fromCacheArray($data);
})->with([
    'empty' => [[]],
    'no timestamp' => [['duration_ms' => 1.0, 'checks' => []]],
    'bad timestamp' => [['timestamp' => 'not a date', 'duration_ms' => 1.0, 'checks' => []]],
    'no checks' => [['timestamp' => '2026-10-30T10:00:00+00:00', 'duration_ms' => 1.0]],
    'bad check' => [['timestamp' => '2026-10-30T10:00:00+00:00', 'duration_ms' => 1.0, 'checks' => ['a' => 'x']]],
    'no duration' => [['timestamp' => '2026-10-30T10:00:00+00:00', 'checks' => []]],
])->throws(InvalidArgumentException::class);

it('bounds socket I/O with LHE_TIMEOUT during a check and restores the previous value', function () {
    config(['laravel-health-endpoints.timeout' => 4]);
    $before = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', '61');

    $spy = new class extends Check
    {
        /** @var list<string|false> */
        public array $seen = [];

        public function name(): string
        {
            return 'socket-spy';
        }

        public function run(): CheckResult
        {
            $this->seen[] = ini_get('default_socket_timeout');

            throw new RuntimeException('boom');
        }
    };
    $targeted = new class extends TargetedCheck
    {
        /** @var list<string|false> */
        public array $seen = [];

        public function name(): string
        {
            return 'socket-spy-targets';
        }

        public function targets(): array
        {
            return ['a', 'b'];
        }

        public function runTarget(string $target): CheckResult
        {
            $this->seen[] = ini_get('default_socket_timeout');
            $this->mysqlnd = ini_get('mysqlnd.net_read_timeout');

            return CheckResult::ok();
        }

        public string|false $mysqlnd = false;
    };
    $mysqlndBefore = ini_get('mysqlnd.net_read_timeout');

    try {
        app(HealthRunner::class)->runAll([$spy, $targeted]);

        expect($spy->seen)->toBe(['4'])
            ->and($targeted->seen)->toBe(['4', '4'])
            ->and(ini_get('default_socket_timeout'))->toBe('61')
            ->and(ini_get('mysqlnd.net_read_timeout'))->toBe($mysqlndBefore);

        if ($mysqlndBefore !== false) {
            expect($targeted->mysqlnd)->toBe('4');
        }
    } finally {
        ini_set('default_socket_timeout', (string) $before);
    }
});

it('recovers a check-level failure once targets() works again and logs the next failure again', function () {
    Log::spy();
    Event::fake();
    $check = new class extends TargetedCheck
    {
        public bool $broken = true;

        public function name(): string
        {
            return 'flaky-targets';
        }

        public function targets(): array
        {
            return $this->broken ? throw new RuntimeException('targets unavailable') : ['a'];
        }

        public function runTarget(string $target): CheckResult
        {
            return CheckResult::ok();
        }
    };
    $runner = app(HealthRunner::class);

    $runner->runCheck($check);
    $check->broken = false;
    $runner->runCheck($check);
    $runner->runCheck($check);
    $check->broken = true;
    $runner->runCheck($check);

    Log::shouldHaveReceived('error')->twice()->withArgs(fn ($m) => str_starts_with($m, '[LHE] flaky-targets check failed'));
    Log::shouldHaveReceived('info')->once()->withArgs(fn ($m) => $m === '[LHE] flaky-targets check recovered');
    Event::assertDispatchedTimes(HealthCheckFailed::class, 2);
    Event::assertDispatched(HealthCheckRecovered::class, fn ($e) => $e->check === 'flaky-targets' && $e->target === null);
    Event::assertDispatchedTimes(HealthCheckRecovered::class, 1);
});
