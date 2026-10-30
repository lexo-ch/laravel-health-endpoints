<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\FailingFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\TwoTargetFixture;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 60,
        'laravel-health-endpoints.discover.enabled' => false,
    ]);
    Cache::store('array')->flush();
    LaravelHealthEndpoints::register(new OkFixtureCheck);
    LaravelHealthEndpoints::register(new TwoTargetFixture(['a' => true, 'b' => false]));
});

it('exits 1 when any check fails and prints a table', function () {
    $this->artisan('lhe:run')
        ->expectsOutputToContain('ok-fixture')
        ->expectsOutputToContain('two-target-fixture')
        ->assertExitCode(1);
});

it('exits 0 when all pass', function () {
    $this->artisan('lhe:run', ['check' => 'ok-fixture'])->expectsOutputToContain('ok')->assertExitCode(0);
});

it('runs a single target', function () {
    $this->artisan('lhe:run', ['check' => 'two-target-fixture', 'target' => 'a'])->assertExitCode(0);
    $this->artisan('lhe:run', ['check' => 'two-target-fixture', 'target' => 'b'])->assertExitCode(1);
});

it('exits 2 for an unknown check', function () {
    $this->artisan('lhe:run', ['check' => 'nope'])->expectsOutputToContain('Unknown health check')->assertExitCode(2);
});

it('exits 2 for an unknown target', function () {
    $this->artisan('lhe:run', ['check' => 'two-target-fixture', 'target' => 'zzz'])->assertExitCode(2);
    $this->artisan('lhe:run', ['check' => 'ok-fixture', 'target' => 'a'])->assertExitCode(2);
});

it('shows failure messages (trusted CLI)', function () {
    LaravelHealthEndpoints::register(new FailingFixtureCheck);

    Artisan::call('lhe:run', ['check' => 'failing-fixture']);

    expect(Artisan::output())->toContain('fail');
});

it('outputs the detailed json report with app info', function () {
    Artisan::call('lhe:run', ['--json' => true]);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['status'])->toBe('fail')
        ->and($data['checks']['ok-fixture']['status'])->toBe('ok')
        ->and($data['checks']['two-target-fixture']['targets']['b']['status'])->toBe('fail')
        ->and($data)->toHaveKeys(['duration_ms', 'cached', 'app'])
        ->and($data['cached'])->toBeFalse();
});

it('bypasses the result cache', function () {
    $counter = new class extends Check
    {
        protected ?string $name = 'counting';

        public int $runs = 0;

        public function run(): CheckResult
        {
            $this->runs++;

            return CheckResult::ok();
        }
    };
    LaravelHealthEndpoints::register($counter);

    Artisan::call('lhe:run', ['check' => $counter->name()]);
    Artisan::call('lhe:run', ['check' => $counter->name()]);

    expect($counter->runs)->toBe(2);
});
