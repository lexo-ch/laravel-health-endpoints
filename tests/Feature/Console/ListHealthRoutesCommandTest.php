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

it('mentions advanced output in the public warning only when it is on', function () {
    app()['env'] = 'production';

    expect(lheRoutesJson()['warnings'][0])->toContain('LHE_OUTPUT=advanced');

    config(['laravel-health-endpoints.output' => 'basic']);
    expect(lheRoutesJson()['warnings'][0])->toContain('PUBLIC')->not->toContain('LHE_OUTPUT');
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

it('warns when the token is configured as a non-string', function () {
    config(['laravel-health-endpoints.token' => ['x'], 'laravel-health-endpoints.allowed_ips' => '']);

    $data = lheRoutesJson();

    expect($data['routes'][0]['protection'])->toBe('token')
        ->and($data['warnings'][0])->toContain('not a string');
});

it('prints json urls with unescaped slashes', function () {
    Artisan::call('lhe:routes', ['--json' => true]);

    expect(Artisan::output())->toContain('"url": "http://example.test/lhe/v1/ping"');
});

it('warns when the package cache store does not exist', function () {
    config(['laravel-health-endpoints.cache_store' => 'nope', 'laravel-health-endpoints.token' => str_repeat('x', 40)]);

    $warnings = lheRoutesJson()['warnings'];

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('LHE_CACHE_STORE "nope"')->toContain('rate limiting');
});

it('warns when the heartbeat store does not exist', function () {
    config(['laravel-health-endpoints.heartbeat_store' => 'nope', 'laravel-health-endpoints.token' => str_repeat('x', 40)]);

    $warnings = lheRoutesJson()['warnings'];

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('LHE_HEARTBEAT_STORE "nope"');
});

it('does not warn about configured stores', function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.heartbeat_store' => 'file',
        'laravel-health-endpoints.token' => str_repeat('x', 40),
    ]);

    expect(lheRoutesJson()['warnings'])->toBe([]);
});

it('shows the output mode', function () {
    expect(lheRoutesJson()['output'])->toBe('advanced');

    config(['laravel-health-endpoints.output' => 'basic']);
    expect(lheRoutesJson()['output'])->toBe('basic');
    $this->artisan('lhe:routes')->expectsOutputToContain('Output: basic (statuses only)')->assertSuccessful();

    config(['laravel-health-endpoints.token' => str_repeat('a', 32)]);
    $this->artisan('lhe:routes')->expectsOutputToContain('token holders can request advanced output')->assertSuccessful();
});

it('warns about disabled result caching on public production endpoints', function () {
    app()['env'] = 'production';
    config(['laravel-health-endpoints.cache_ttl' => 0]);

    expect(lheRoutesJson()['warnings'])->toContain('LHE_CACHE_TTL=0 on PUBLIC endpoints: every request runs the checks against the database, Redis and storage. Keep result caching on, or set a token or an IP allowlist.');

    config(['laravel-health-endpoints.token' => str_repeat('a', 32)]);
    expect(implode("\n", lheRoutesJson()['warnings']))->not->toContain('LHE_CACHE_TTL');

    config(['laravel-health-endpoints.token' => '', 'laravel-health-endpoints.cache_ttl' => 10]);
    expect(implode("\n", lheRoutesJson()['warnings']))->not->toContain('LHE_CACHE_TTL');
});
