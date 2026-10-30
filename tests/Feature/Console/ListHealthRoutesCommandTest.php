<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\TwoTargetFixture;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\CustomPathCheck;

beforeEach(function () {
    config([
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.database.enabled' => true,
    ]);
    URL::forceRootUrl('http://example.test');
    LaravelHealthEndpoints::register(new OkFixtureCheck);
    LaravelHealthEndpoints::register(new TwoTargetFixture(['a' => true, 'b' => true]));
    LaravelHealthEndpoints::register(CustomPathCheck::class);
});

function lheRoutesJson(): array
{
    Artisan::call('lhe:routes', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('lists all endpoints in a table', function () {
    Artisan::call('lhe:routes');
    $out = Artisan::output();

    foreach (['/lhe/v1 ', '/lhe/v1/ping', '/lhe/v1/database', '/lhe/v1/two-target-fixture/a', '/lhe/v1/billing/stripe'] as $path) {
        expect($out)->toContain('http://example.test'.$path);
    }
    expect($out)->toContain('GET|HEAD');
});

it('outputs valid json with routes and warnings', function () {
    $data = lheRoutesJson();
    $urls = array_column($data['routes'], 'url');

    expect($data)->toHaveKeys(['routes', 'warnings'])
        ->and($urls)->toContain('http://example.test/lhe/v1', 'http://example.test/lhe/v1/ping', 'http://example.test/lhe/v1/database')
        ->toContain('http://example.test/lhe/v1/two-target-fixture/b', 'http://example.test/lhe/v1/billing/stripe')
        ->and($data['routes'][0]['method'])->toBe('GET|HEAD')
        ->and($data['routes'][0]['protection'])->toBe('none');
});

it('reports protection modes', function (string $token, string $ips, string $expected) {
    config(['laravel-health-endpoints.token' => $token, 'laravel-health-endpoints.allowed_ips' => $ips]);

    expect(lheRoutesJson()['routes'][0]['protection'])->toBe($expected);
})->with([
    ['', '', 'none'],
    [str_repeat('x', 40), '', 'token'],
    ['', '10.0.0.1', 'ip allowlist'],
    [str_repeat('x', 40), '10.0.0.1', 'token + ip allowlist'],
]);

it('never prints the token', function () {
    $token = 'SuperSecretToken-'.str_repeat('z', 30);
    config(['laravel-health-endpoints.token' => $token]);

    Artisan::call('lhe:routes');
    $table = Artisan::output();
    Artisan::call('lhe:routes', ['--json' => true]);

    expect($table)->not->toContain($token)->and(Artisan::output())->not->toContain($token);
});

it('warns when public in production', function () {
    app()['env'] = 'production';

    $this->artisan('lhe:routes')->expectsOutputToContain('PUBLIC')->assertSuccessful();
    expect(lheRoutesJson()['warnings'][0])->toContain('PUBLIC');
});

it('does not warn public outside production or when protected', function () {
    expect(lheRoutesJson()['warnings'])->toBe([]);

    app()['env'] = 'production';
    config(['laravel-health-endpoints.allowed_ips' => '10.0.0.1']);
    expect(lheRoutesJson()['warnings'])->toBe([]);
});

it('warns on a short token without revealing it', function () {
    config(['laravel-health-endpoints.token' => 'short-token']);

    $this->artisan('lhe:routes')->expectsOutputToContain('shorter than 32')->assertSuccessful();
    expect(lheRoutesJson()['warnings'][0])->toContain('shorter than 32')->not->toContain('short-token');
});

it('warns when a check cannot list its targets', function () {
    LaravelHealthEndpoints::register(new class extends TargetedCheck
    {
        protected ?string $name = 'broken-targets';

        public function targets(): array
        {
            throw new RuntimeException('boom password=hunter2');
        }

        public function runTarget(string $target): CheckResult
        {
            return CheckResult::ok();
        }
    });

    $warnings = lheRoutesJson()['warnings'];

    expect($warnings[0])->toContain('broken-targets')->not->toContain('hunter2');
    $this->artisan('lhe:routes')->expectsOutputToContain('broken-targets')->assertSuccessful();
});
