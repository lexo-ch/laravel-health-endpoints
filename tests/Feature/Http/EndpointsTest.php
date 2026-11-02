<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;
use LEXO\LaravelHealthEndpoints\Http\Middleware\RestrictHealthIps;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\FailingFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\OkFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\TwoTargetFixture;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry\CustomPathCheck;

beforeEach(function () {
    config([
        'laravel-health-endpoints.cache_store' => 'array',
        'laravel-health-endpoints.cache_ttl' => 0,
        'laravel-health-endpoints.discover.enabled' => false,
    ]);
    Cache::store('array')->flush();

    LaravelHealthEndpoints::register(new OkFixtureCheck);
    LaravelHealthEndpoints::register(new TwoTargetFixture(['a' => true, 'mysql.read' => true, 'redis:cache' => true]));
    LaravelHealthEndpoints::register(CustomPathCheck::class);
    lheReloadRoutes();
});

it('returns 200 when all pass', function () {
    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.ok-fixture.status', 'ok')
        ->assertJsonPath('checks.two-target-fixture.status', 'ok')
        ->assertJsonPath('checks.custom-path.status', 'ok');
});

it('returns 503 when any fails', function () {
    LaravelHealthEndpoints::register(new FailingFixtureCheck);

    $this->getJson('/lhe/v1')->assertStatus(503)->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.failing-fixture.status', 'fail')
        ->assertJsonPath('checks.ok-fixture.status', 'ok');
});

it('runs a single check', function () {
    LaravelHealthEndpoints::register(new FailingFixtureCheck);

    $this->getJson('/lhe/v1/ok-fixture')->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonMissingPath('checks.failing-fixture');
    $this->getJson('/lhe/v1/failing-fixture')->assertStatus(503)->assertJsonPath('status', 'fail');
});

it('runs a single check and target', function () {
    $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk()->assertJsonPath('checks.two-target-fixture.status', 'ok');
});

it('accepts dotted and colon target names when configured', function (string $target) {
    $this->getJson('/lhe/v1/two-target-fixture/'.$target)->assertOk()
        ->assertJsonPath('checks.two-target-fixture.targets', [$target => ['status' => 'ok', 'duration_ms' => 0]]);
})->with(['mysql.read', 'redis:cache']);

it('404s unknown check and target', function () {
    $this->getJson('/lhe/v1/nope')->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Not found']);
    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Not found']);
    $this->getJson('/lhe/v1/ok-fixture/a')->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Not found']);
});

it('404s traversal-like and malformed paths as JSON', function (string $path) {
    $this->getJson($path)->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Not found']);
})->with([
    '/lhe/v1/two-target-fixture/..%2F..%2Fetc',
    '/lhe/v1/two-target-fixture/%2E%2E',
    '/lhe/v1/two-target-fixture/a/b',
    '/lhe/v1/two-target-fixture/'.str_repeat('a', 200),
    '/lhe/v1/two-target-fixture/a%00',
    '/lhe/v1/Ok-Fixture',
    '/lhe/v1/1abc',
    '/lhe/v1/a/b/c/d',
    '/lhe/v1/billing',
    '/lhe/v1/billing/stripe/extra',
]);

it('never runs targets for unconfigured names', function () {
    $check = new class extends TargetedCheck
    {
        /** @var list<string> */
        public array $ran = [];

        public function name(): string
        {
            return 'spy';
        }

        public function targets(): array
        {
            return ['a'];
        }

        public function runTarget(string $target): CheckResult
        {
            $this->ran[] = $target;

            return CheckResult::ok();
        }
    };
    LaravelHealthEndpoints::register($check);

    $this->getJson('/lhe/v1/spy/..')->assertNotFound();
    $this->getJson('/lhe/v1/spy/b')->assertNotFound();

    expect($check->ran)->toBe([]);
});

it('answers HEAD with same status and empty body', function () {
    $this->call('HEAD', '/lhe/v1')->assertOk()->assertContent('');
    $this->call('HEAD', '/lhe/v1/nope')->assertNotFound()->assertContent('');

    LaravelHealthEndpoints::register(new FailingFixtureCheck);
    $this->call('HEAD', '/lhe/v1')->assertStatus(503)->assertContent('');
});

it('405s other methods as JSON', function (string $method, string $uri) {
    $this->json($method, $uri)->assertStatus(405)
        ->assertExactJson(['status' => 'error', 'message' => 'Method not allowed'])
        ->assertHeader('Allow', 'GET, HEAD');
})->with([
    ['POST', '/lhe/v1'],
    ['PUT', '/lhe/v1/ping'],
    ['DELETE', '/lhe/v1/ok-fixture'],
    ['PATCH', '/lhe/v1/two-target-fixture/a'],
    ['POST', '/lhe/v1/billing/stripe'],
    ['OPTIONS', '/lhe/v1'],
    ['POST', '/lhe/v1/does/not/exist'],
    ['TRACE', '/lhe/v1/ping'],
    ['CONNECT', '/lhe/v1'],
    ['PROPFIND', '/lhe/v1/ok-fixture'],
    ['QUERY', '/lhe/v1/two-target-fixture/a'],
    ['MKCOL', '/lhe/v1/does/not/exist'],
]);

it('answers uncommon verbs with the package 405 after auth and with security headers', function (string $method) {
    $assertHeaders = fn ($response) => $response
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'application/json');

    $assertHeaders($this->json($method, '/lhe/v1/ping')->assertStatus(405)
        ->assertExactJson(['status' => 'error', 'message' => 'Method not allowed']));

    config(['laravel-health-endpoints.token' => 'secret-token']);

    $assertHeaders($this->json($method, '/lhe/v1/ping')->assertUnauthorized()
        ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']));
    $this->json($method, '/lhe/v1/ping', [], ['Authorization' => 'Bearer secret-token'])->assertStatus(405);
})->with(['TRACE', 'PROPFIND', 'QUERY']);

it('pings without running checks', function () {
    $check = new class extends Check
    {
        public int $runs = 0;

        public function name(): string
        {
            return 'ping-spy';
        }

        public function run(): CheckResult
        {
            $this->runs++;

            return CheckResult::fail('down');
        }
    };
    LaravelHealthEndpoints::register($check);
    config(['laravel-health-endpoints.cache_ttl' => 10]);

    $this->getJson('/lhe/v1/ping')->assertOk()->assertExactJson(['status' => 'ok']);
    $this->getJson('/lhe/v1/ping')->assertOk();

    expect($check->runs)->toBe(0);
    $storage = (fn () => $this->storage)->call(Cache::store('array')->getStore());
    expect(array_filter(array_keys($storage), fn (string $k) => str_starts_with($k, 'lhe:result:')))->toBe([]);
});

it('returns only the status from ping in every output mode', function (string $mode) {
    config(['laravel-health-endpoints.output' => $mode]);

    $this->getJson('/lhe/v1/ping')->assertOk()->assertExactJson(['status' => 'ok']);
})->with(['basic', 'advanced']);

it('returns only statuses with basic output', function () {
    config(['laravel-health-endpoints.output' => 'basic']);
    lheFreshRegistry(new OkFixtureCheck, new TwoTargetFixture(['a' => true, 'b' => false]), new FailingFixtureCheck('db password=hunter2 failed'));

    $this->getJson('/lhe/v1')->assertStatus(503)->assertExactJson([
        'status' => 'fail',
        'checks' => [
            'ok-fixture' => ['status' => 'ok'],
            'two-target-fixture' => ['status' => 'fail', 'targets' => ['a' => ['status' => 'ok'], 'b' => ['status' => 'fail']]],
            'failing-fixture' => ['status' => 'fail'],
        ],
    ]);

    $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['two-target-fixture' => ['status' => 'ok', 'targets' => ['a' => ['status' => 'ok']]]]]);
});

it('defaults to basic output', function () {
    config(['laravel-health-endpoints.output' => null]);

    $this->getJson('/lhe/v1/ok-fixture')->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['ok-fixture' => ['status' => 'ok']]]);
});

it('never includes a version or app info in check reports', function () {
    foreach (['/lhe/v1', '/lhe/v1/ok-fixture', '/lhe/v1/two-target-fixture/a'] as $url) {
        $this->getJson($url)->assertOk()->assertJsonMissingPath('app')->assertJsonMissingPath('version');
    }
});

it('serves custom routes', function () {
    $this->getJson('/lhe/v1/billing/stripe')->assertOk()->assertJsonPath('checks.custom-path.status', 'ok')
        ->assertJsonMissingPath('checks.ok-fixture');
    $this->getJson('/lhe/v1/custom-path')->assertOk()->assertJsonPath('checks.custom-path.status', 'ok');
    expect(Route::has('lhe.custom.custom-path'))->toBeTrue();
});

it('registers named routes', function () {
    foreach (['lhe.all', 'lhe.ping', 'lhe.check', 'lhe.fallback'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }

    expect(Route::getRoutes()->getByName('lhe.all')?->gatherMiddleware())->not->toContain('web')->not->toContain('api');
});

it('serves cached reports', function () {
    config(['laravel-health-endpoints.cache_ttl' => 10]);

    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('cached', true);
    $this->getJson('/lhe/v1/two-target-fixture')->assertOk()->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk()->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk()->assertJsonPath('cached', true);
});

it('keeps the http status for cached failures', function () {
    config(['laravel-health-endpoints.cache_ttl' => 10]);
    LaravelHealthEndpoints::register(new FailingFixtureCheck);

    $this->getJson('/lhe/v1')->assertStatus(503)->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1')->assertStatus(503)->assertJsonPath('cached', true);
});

it('does not cache lookups for unknown targets', function () {
    config(['laravel-health-endpoints.cache_ttl' => 10]);

    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertNotFound();

    $storage = (fn () => $this->storage)->call(Cache::store('array')->getStore());
    expect(array_filter(array_keys($storage), fn (string $k) => str_starts_with($k, 'lhe:result:')))->toBe([]);
});

it('never shows the debug page', function () {
    config(['app.debug' => true]);
    Log::spy();
    app()->bind(HealthCheckRegistry::class, fn () => throw new RuntimeException('secret internal detail /var/www'));

    $this->getJson('/lhe/v1')->assertStatus(503)
        ->assertExactJson(['status' => 'fail', 'message' => 'Health check error'])
        ->assertJsonMissingPath('exception')
        ->assertDontSee('secret internal detail');
    $this->get('/lhe/v1/ok-fixture')->assertStatus(503)->assertExactJson(['status' => 'fail', 'message' => 'Health check error']);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === '[LHE] Health endpoint error'
        && $context['exception'] instanceof RuntimeException
        && $context['method'] === 'GET'
        && $context['path'] === 'lhe/v1')->once();
});

it('logs endpoint errors without the query string', function () {
    config(['laravel-health-endpoints.token' => 'secret-token']);
    Log::spy();
    app()->bind(HealthCheckRegistry::class, fn () => throw new RuntimeException('boom'));

    $this->getJson('/lhe/v1?token=secret-token')->assertStatus(503);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => ! str_contains((string) json_encode([$message, $context['path'], $context['method']]), 'secret-token')
        && array_keys($context) === ['exception', 'method', 'path'])->once();
});

it('logs the same endpoint error at most once per minute per exception class', function () {
    Log::spy();
    app()->bind(HealthCheckRegistry::class, fn () => throw new RuntimeException('boom'));

    $this->getJson('/lhe/v1')->assertStatus(503);
    $this->getJson('/lhe/v1/ok-fixture')->assertStatus(503);
    Log::shouldHaveReceived('error')->with('[LHE] Health endpoint error', Mockery::any())->once();

    app()->bind(HealthCheckRegistry::class, fn () => throw new LogicException('other'));
    $this->getJson('/lhe/v1')->assertStatus(503);
    Log::shouldHaveReceived('error')->with('[LHE] Health endpoint error', Mockery::any())->twice();

    $this->travel(61)->seconds();
    $this->getJson('/lhe/v1')->assertStatus(503);
    Log::shouldHaveReceived('error')->with('[LHE] Health endpoint error', Mockery::any())->times(3);
});

it('still logs endpoint errors when the throttle store is unavailable', function () {
    config(['laravel-health-endpoints.cache_store' => 'missing-store']);
    Log::spy();
    app()->bind(HealthCheckRegistry::class, fn () => throw new RuntimeException('boom'));

    $this->getJson('/lhe/v1')->assertStatus(503)->assertExactJson(['status' => 'fail', 'message' => 'Health check error']);
    $this->getJson('/lhe/v1')->assertStatus(503);

    Log::shouldHaveReceived('error')->with('[LHE] Health endpoint error', Mockery::any())->twice();
});

it('turns unexpected middleware failures into the package json', function () {
    config(['app.debug' => true]);
    app()->bind(RestrictHealthIps::class, fn () => new class
    {
        public function handle(): never
        {
            throw new RuntimeException('secret internal detail');
        }
    });

    $this->getJson('/lhe/v1')->assertStatus(503)
        ->assertExactJson(['status' => 'fail', 'message' => 'Health check error'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('never consults the result cache for unconfigured targets', function () {
    config(['laravel-health-endpoints.cache_ttl' => 10]);
    $planted = app(HealthRunner::class)->runAll([new OkFixtureCheck])->toCacheArray();
    Cache::store('array')->put('lhe:result:'.sha1('check:two-target-fixture:zzz'), $planted, 10);

    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertNotFound()->assertExactJson(['status' => 'error', 'message' => 'Not found']);
});

it('reports a failure when a target list cannot be loaded', function () {
    LaravelHealthEndpoints::register(new class extends TargetedCheck
    {
        public function name(): string
        {
            return 'broken-targets';
        }

        public function targets(): array
        {
            throw new RuntimeException('config unreadable');
        }

        public function runTarget(string $target): CheckResult
        {
            return CheckResult::ok();
        }
    });
    config(['laravel-health-endpoints.cache_ttl' => 10]);

    $this->getJson('/lhe/v1/broken-targets/anything')->assertStatus(503)->assertJsonPath('checks.broken-targets.status', 'fail');

    $storage = (fn () => $this->storage)->call(Cache::store('array')->getStore());
    expect(array_filter(array_keys($storage), fn (string $k) => str_starts_with($k, 'lhe:result:')))->toBe([]);
});

it('uses only cacheable controller actions', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'lhe.'));

    expect($routes)->toHaveCount(5);
    foreach ($routes as $route) {
        expect($route->getAction('uses'))->toBeString();
        $route->prepareForSerialization();
    }
});

function lheFreshRegistry(object ...$checks): void
{
    app()->forgetInstance(HealthCheckRegistry::class);
    LaravelHealthEndpoints::clearResolvedInstances();

    foreach ($checks as $check) {
        LaravelHealthEndpoints::register($check);
    }

    lheReloadRoutes();
}

it('encodes an empty checks map as a JSON object', function () {
    lheFreshRegistry();

    $response = $this->getJson('/lhe/v1')->assertOk();

    expect($response->getContent())->toContain('"checks":{}')->not->toContain('"checks":[]');
});

it('keeps numeric target names as JSON object keys', function () {
    lheFreshRegistry(new TwoTargetFixture(['0' => true, '1' => true]));

    $content = (string) $this->getJson('/lhe/v1')->assertOk()->getContent();
    $decoded = json_decode($content);

    expect($decoded->checks->{'two-target-fixture'}->targets)->toBeObject()
        ->and(count(get_object_vars($decoded->checks->{'two-target-fixture'}->targets)))->toBe(2)
        ->and($content)->toContain('"targets":{"0":{');

    $single = json_decode((string) $this->getJson('/lhe/v1/two-target-fixture/1')->assertOk()->getContent());
    expect($single->checks->{'two-target-fixture'}->targets)->toBeObject();
});

it('sends unicode and slashes unescaped but keeps HTML-safe escaping', function () {
    lheFreshRegistry(new FailingFixtureCheck('Café/Ops — <b>'));

    $content = (string) $this->getJson('/lhe/v1/failing-fixture')->assertStatus(503)->getContent();

    expect($content)->toContain('"message":"Café/Ops — ')
        ->not->toContain('<b>')
        ->and(json_decode($content, true)['checks']['failing-fixture']['message'])->toBe('Café/Ops — <b>');
});

it('keeps the report encodable when meta holds NAN, INF or invalid UTF-8', function () {
    lheFreshRegistry(new class extends Check
    {
        protected ?string $name = 'odd-meta';

        public function run(): CheckResult
        {
            return CheckResult::ok(meta: ['nan' => NAN, 'inf' => -INF, "bad\xB1key" => "bad\xB1value", 'nested' => ['x' => INF], 'n' => 1.5]);
        }
    });

    $response = $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('status', 'ok');
    $meta = $response->json('checks.odd-meta.meta');

    expect($meta['nan'])->toBeNull()
        ->and($meta['inf'])->toBeNull()
        ->and($meta['nested']['x'])->toBeNull()
        ->and($meta['n'])->toBe(1.5)
        ->and($meta)->toHaveKey('bad?key')
        ->and($meta['bad?key'])->toBe('bad?value');
});

it('keeps the report encodable when a target name is invalid UTF-8', function () {
    lheFreshRegistry(new TwoTargetFixture(["ok\xB1" => true]));

    $response = $this->getJson('/lhe/v1')->assertOk();

    expect(array_keys($response->json('checks.two-target-fixture.targets')))->toBe(['ok?']);
});
