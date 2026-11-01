<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\FailingFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\TwoTargetFixture;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
        'laravel-health-endpoints.token' => '',
        'laravel-health-endpoints.allowed_ips' => '',
    ]);
    Cache::store('array')->flush();

    LaravelHealthEndpoints::register(new OkFixtureCheck);
    LaravelHealthEndpoints::register(new TwoTargetFixture);
    lheReloadRoutes();
});

function lheStorageKeys(string $store): array
{
    return array_keys((fn () => $this->storage)->call(Cache::store($store)->getStore()));
}

it('requires the token when set', function () {
    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1')->assertUnauthorized()->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
    $this->getJson('/lhe/v1?token=wrong')->assertUnauthorized();
    $this->getJson('/lhe/v1?token=secret-token')->assertOk();
    $this->getJson('/lhe/v1', ['Authorization' => 'Bearer secret-token'])->assertOk();
});

it('protects every route with the token', function (string $uri) {
    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson($uri)->assertUnauthorized()->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
})->with(['/lhe/v1/ping', '/lhe/v1/ok-fixture', '/lhe/v1/two-target-fixture/a', '/lhe/v1/a/b/c']);

it('treats malformed tokens as missing', function (string $uri, array $headers) {
    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson($uri, $headers)->assertUnauthorized()->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
})->with([
    'array query' => ['/lhe/v1?token[]=secret-token', []],
    'nested array query' => ['/lhe/v1?token[a][b]=secret-token', []],
    'empty bearer' => ['/lhe/v1', ['Authorization' => 'Bearer ']],
    'empty query' => ['/lhe/v1?token=', []],
    'header wins over query' => ['/lhe/v1?token=secret-token', ['Authorization' => 'Bearer wrong']],
    'basic auth' => ['/lhe/v1', ['Authorization' => 'Basic '.base64_encode('secret-token:')]],
    'prefix only' => ['/lhe/v1?token=secret', []],
]);

it('accepts the bearer scheme case-insensitively', function (string $scheme) {
    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1/ping', ['Authorization' => $scheme.' secret-token'])->assertOk();
    $this->getJson('/lhe/v1/ping', ['Authorization' => $scheme.' wrong'])->assertUnauthorized();
})->with(['bearer', 'Bearer', 'BEARER']);

it('accepts the query token when the bearer header is empty', function () {
    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1?token=secret-token', ['Authorization' => 'Bearer '])->assertOk();
});

it('authenticates before 404 and 405', function () {
    config(['laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1/nope')->assertUnauthorized();
    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertUnauthorized();
    $this->getJson('/lhe/v1/Not_A/Valid/Path')->assertUnauthorized();
    $this->postJson('/lhe/v1')->assertUnauthorized();
    $this->getJson('/lhe/v1/nope?token=secret-token')->assertNotFound();
});

it('checks the IP allowlist before the token', function () {
    config(['laravel-health-endpoints.token' => 'secret-token', 'laravel-health-endpoints.allowed_ips' => '10.0.0.0/8']);

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])->getJson('/lhe/v1/nope')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson('/lhe/v1/nope')->assertUnauthorized();
});

it('enforces an IP allowlist configured as a PHP array', function () {
    config(['laravel-health-endpoints.allowed_ips' => ['10.0.0.1']]);

    $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])->getJson('/lhe/v1/ping')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/lhe/v1/ping')->assertOk();
});

it('denies every request when the token is configured as a non-string', function (mixed $token) {
    config(['laravel-health-endpoints.token' => $token]);

    foreach (['/lhe/v1', '/lhe/v1/ping', '/lhe/v1/ping?token=x', '/lhe/v1/nope'] as $uri) {
        $this->getJson($uri, ['Authorization' => 'Bearer x'])->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
    }
})->with(['array' => [['x']], 'int' => [12345], 'object' => [new stdClass]]);

it('enforces the IP allowlist', function (string $ip, int $status) {
    config(['laravel-health-endpoints.allowed_ips' => '10.0.0.0/8, 2001:db8::/32']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/lhe/v1')->assertStatus($status);

    if ($status === 403) {
        $response->assertExactJson(['status' => 'error', 'message' => 'Forbidden']);
    }
})->with([['10.1.2.3', 200], ['192.168.1.5', 403], ['2001:db8::1', 200], ['2001:db9::1', 403], ['127.0.0.1', 403]]);

it('denies requests without a client IP when an allowlist is set', function () {
    config(['laravel-health-endpoints.allowed_ips' => '10.0.0.0/8']);

    $this->withServerVariables(['REMOTE_ADDR' => ''])->getJson('/lhe/v1/ping')->assertForbidden();
});

it('ignores X-Forwarded-For without trusted proxies', function () {
    config(['laravel-health-endpoints.allowed_ips' => '10.0.0.0/8']);

    $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
        ->getJson('/lhe/v1', ['X-Forwarded-For' => '10.0.0.1', 'X-Real-IP' => '10.0.0.1', 'Client-IP' => '10.0.0.1'])
        ->assertForbidden();
});

it('rate limits', function () {
    config(['laravel-health-endpoints.rate_limit' => 2]);

    $this->getJson('/lhe/v1')->assertOk();
    $this->getJson('/lhe/v1/nope')->assertNotFound();
    $this->getJson('/lhe/v1')->assertStatus(429)
        ->assertExactJson(['status' => 'error', 'message' => 'Too many requests'])
        ->assertHeader('Retry-After')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('rate limits per client IP', function () {
    config(['laravel-health-endpoints.rate_limit' => 1]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/lhe/v1/ping')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/lhe/v1/ping')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/lhe/v1/ping')->assertOk();
});

it('rate limits before authentication', function () {
    config(['laravel-health-endpoints.rate_limit' => 2, 'laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1?token=a')->assertUnauthorized();
    $this->getJson('/lhe/v1?token=b')->assertUnauthorized();
    $this->getJson('/lhe/v1?token=secret-token')->assertStatus(429);
});

it('does not use the token in the rate limiter key', function () {
    config(['laravel-health-endpoints.rate_limit' => 5, 'laravel-health-endpoints.token' => 'secret-token']);

    $this->getJson('/lhe/v1?token=secret-token')->assertOk();

    expect((new RateLimiter(Cache::store('array')))->attempts('lhe:'.sha1('127.0.0.1')))->toBe(1);
});

it('fails open when the rate limiter store is unavailable', function () {
    config(['laravel-health-endpoints.cache_store' => 'missing-store', 'laravel-health-endpoints.rate_limit' => 1]);

    $this->getJson('/lhe/v1/ping')->assertOk();
    $this->getJson('/lhe/v1/ping')->assertOk();
});

it('rate limits on the package store and never touches the app cache or limiter store', function () {
    $touched = false;
    Cache::extend('lhe-tripwire', function () use (&$touched) {
        $touched = true;

        throw new RuntimeException('app store must not be used');
    });
    config([
        'cache.stores.tripwire' => ['driver' => 'lhe-tripwire'],
        'cache.default' => 'tripwire',
        'cache.limiter' => 'tripwire',
        'laravel-health-endpoints.rate_limit' => 1,
    ]);
    app()->forgetInstance(RateLimiter::class);
    Illuminate\Support\Facades\RateLimiter::clearResolvedInstances();

    $this->getJson('/lhe/v1/ping')->assertOk();
    $this->getJson('/lhe/v1/ping')->assertStatus(429);

    expect($touched)->toBeFalse()
        ->and((new RateLimiter(Cache::store('array')))->attempts('lhe:'.sha1('127.0.0.1')))->toBe(1);
});

it('sends security headers on every response', function (string $method, string $uri, array $config) {
    config($config);

    $response = $this->json($method, $uri);

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'application/json');
})->with([
    '200' => ['GET', '/lhe/v1', []],
    'ping' => ['GET', '/lhe/v1/ping', []],
    '404' => ['GET', '/lhe/v1/nope', []],
    '405' => ['POST', '/lhe/v1', []],
    '401' => ['GET', '/lhe/v1', ['laravel-health-endpoints.token' => 'secret-token']],
    '403' => ['GET', '/lhe/v1', ['laravel-health-endpoints.allowed_ips' => '10.0.0.0/8']],
    '429' => ['GET', '/lhe/v1', ['laravel-health-endpoints.rate_limit' => 1]],
]);

it('sends security headers on HEAD and 503 responses', function () {
    LaravelHealthEndpoints::register(new FailingFixtureCheck);

    $this->call('HEAD', '/lhe/v1')->assertStatus(503)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->getJson('/lhe/v1')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
});

it('sets no cookies and no CORS headers', function (string $uri) {
    $response = $this->get($uri, ['Origin' => 'https://evil.example']);

    $response->assertCookieMissing((string) config('session.cookie'))
        ->assertCookieMissing('XSRF-TOKEN')
        ->assertHeaderMissing('Set-Cookie')
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
    expect($response->headers->getCookies())->toBe([]);
})->with(['/lhe/v1', '/lhe/v1/ping', '/lhe/v1/nope']);

it('returns the same output with or without protection', function () {
    lheUseComposerJson('{"version": "2.4.1"}');
    config(['laravel-health-endpoints.cache_ttl' => 0]);
    LaravelHealthEndpoints::register(new FailingFixtureCheck('db password=hunter2 failed'));
    $stable = fn (array $json): array => Arr::where(
        Arr::dot($json),
        fn (mixed $v, string $k): bool => $k !== 'timestamp' && ! str_ends_with($k, 'duration_ms'),
    );

    $public = $this->getJson('/lhe/v1')->assertStatus(503)->assertDontSee('hunter2');
    $publicPing = $this->getJson('/lhe/v1/ping')->assertOk()->json();

    config(['laravel-health-endpoints.token' => 'secret-token']);
    $protected = $this->getJson('/lhe/v1', ['Authorization' => 'Bearer secret-token'])->assertStatus(503);
    $protectedPing = $this->getJson('/lhe/v1/ping', ['Authorization' => 'Bearer secret-token'])->assertOk()->json();

    expect($stable($public->json()))->toBe($stable($protected->json()))
        ->and($stable($publicPing))->toBe($stable($protectedPing))
        ->and($publicPing['version'])->toBe('2.4.1');
    $public->assertJsonPath('checks.ok-fixture.message', 'fine')
        ->assertJsonStructure(['status', 'timestamp', 'duration_ms', 'cached', 'checks'])
        ->assertJsonMissingPath('app');
});

it('never logs, echoes or caches the token', function () {
    config(['laravel-health-endpoints.token' => 'secret-token', 'laravel-health-endpoints.cache_ttl' => 10, 'laravel-health-endpoints.rate_limit' => 100]);
    LaravelHealthEndpoints::register(new FailingFixtureCheck);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    $responses = [
        $this->getJson('/lhe/v1?token=secret-token'),
        $this->getJson('/lhe/v1/failing-fixture', ['Authorization' => 'Bearer secret-token']),
        $this->getJson('/lhe/v1/nope?token=secret-token'),
        $this->getJson('/lhe/v1?token=secret-token-wrong'),
    ];

    expect($logged)->not->toBeEmpty();
    foreach ($logged as $e) {
        expect($e->message.json_encode($e->context))->not->toContain('secret-token');
    }
    foreach ($responses as $response) {
        expect($response->getContent().json_encode($response->headers->all()))->not->toContain('secret-token');
    }
    foreach (['array', (string) config('cache.default')] as $store) {
        $storage = (fn () => $this->storage)->call(Cache::store($store)->getStore());
        expect(serialize($storage))->not->toContain('secret-token');
    }
});

it('warns once per day in production when public', function () {
    app()->instance('env', 'production');
    Log::spy();

    $this->getJson('/lhe/v1/ping')->assertOk();
    $this->getJson('/lhe/v1/ping')->assertOk();

    Log::shouldHaveReceived('warning')
        ->with('[LHE] Health endpoints are public and expose check details. Set LHE_TOKEN or LHE_ALLOWED_IPS.')
        ->once();

    $this->travel(86401)->seconds();
    $this->getJson('/lhe/v1/ping')->assertOk();

    Log::shouldHaveReceived('warning')->twice();
});

it('does not warn when protected or outside production', function () {
    Log::spy();

    $this->getJson('/lhe/v1/ping')->assertOk();

    app()->instance('env', 'production');
    config(['laravel-health-endpoints.token' => 'secret-token']);
    $this->getJson('/lhe/v1/ping?token=secret-token')->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('still answers when the warning store is unavailable', function () {
    app()->instance('env', 'production');
    config(['laravel-health-endpoints.cache_store' => 'missing-store']);

    $this->getJson('/lhe/v1')->assertOk();
});

it('treats a zero rate limit as the default', function () {
    config(['laravel-health-endpoints.rate_limit' => 0]);

    expect(app(Settings::class)->rateLimit())->toBe(60);
    $this->getJson('/lhe/v1/ping')->assertOk();
});
