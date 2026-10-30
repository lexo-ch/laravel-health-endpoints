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
    LaravelHealthEndpoints::register(new FailingFixtureCheck('disk exploded'));

    Artisan::call('lhe:run', ['check' => 'failing-fixture']);

    expect(Artisan::output())->toContain('fail')->toContain('disk exploded');
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
    $check = new class extends Check
    {
        protected ?string $name = 'flip';

        public bool $ok = true;

        public int $runs = 0;

        public function run(): CheckResult
        {
            $this->runs++;

            return $this->ok ? CheckResult::ok() : CheckResult::fail('now failing');
        }
    };
    LaravelHealthEndpoints::register($check);
    lheReloadRoutes();

    // Populate the result cache through the HTTP endpoint while the check is ok.
    $this->getJson('/lhe/v1/flip')->assertOk();
    $this->getJson('/lhe/v1/flip')->assertOk();
    expect($check->runs)->toBe(1);

    $check->ok = false;

    // Cached HTTP answer is stale, the CLI sees the fresh failure.
    $this->getJson('/lhe/v1/flip')->assertOk();
    $this->artisan('lhe:run', ['check' => 'flip'])->assertExitCode(1);
    expect($check->runs)->toBe(2);

    Artisan::call('lhe:run', ['--json' => true]);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($check->runs)->toBe(3)
        ->and($data['checks']['flip']['status'])->toBe('fail')
        ->and($data['checks']['flip']['message'])->toBe('now failing')
        ->and($data['cached'])->toBeFalse();
});
