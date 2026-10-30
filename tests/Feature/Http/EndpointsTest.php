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
    config(['laravel-health-endpoints.details' => 'always']);

    $this->getJson('/lhe/v1/two-target-fixture/'.$target)->assertOk()
        ->assertJsonPath('checks.two-target-fixture.targets', [$target => ['status' => 'ok', 'duration_ms' => 0]]);
})->with(['mysql.read', 'redis:cache']);

it('404s unknown check and target', function () {
    $this->getJson('/lhe/v1/nope')->assertNotFound()->assertExactJson(['status' => 'fail', 'message' => 'Not found']);
    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertNotFound()->assertExactJson(['status' => 'fail', 'message' => 'Not found']);
    $this->getJson('/lhe/v1/ok-fixture/a')->assertNotFound()->assertExactJson(['status' => 'fail', 'message' => 'Not found']);
});

it('404s traversal-like and malformed paths as JSON', function (string $path) {
    $this->getJson($path)->assertNotFound()->assertExactJson(['status' => 'fail', 'message' => 'Not found']);
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
        ->assertExactJson(['status' => 'fail', 'message' => 'Method not allowed'])
        ->assertHeader('Allow', 'GET, HEAD');
})->with([
    ['POST', '/lhe/v1'],
    ['PUT', '/lhe/v1/ping'],
    ['DELETE', '/lhe/v1/ok-fixture'],
    ['PATCH', '/lhe/v1/two-target-fixture/a'],
    ['POST', '/lhe/v1/billing/stripe'],
    ['OPTIONS', '/lhe/v1'],
    ['POST', '/lhe/v1/does/not/exist'],
]);

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

    $this->freezeTime();
    $this->getJson('/lhe/v1/ping')->assertOk()->assertExactJson(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
    $this->getJson('/lhe/v1/ping')->assertOk();

    expect($check->runs)->toBe(0);
    $storage = (fn () => $this->storage)->call(Cache::store('array')->getStore());
    expect(array_filter(array_keys($storage), fn (string $k) => str_starts_with($k, 'lhe:result:')))->toBe([]);
});

it('includes app info in ping only when detailed', function () {
    config(['laravel-health-endpoints.details' => 'always', 'laravel-health-endpoints.app_info.version' => '2.4.1', 'app.name' => 'Acme']);

    $this->getJson('/lhe/v1/ping')->assertOk()
        ->assertJsonPath('app', ['name' => 'Acme', 'environment' => 'testing', 'version' => '2.4.1']);

    config(['laravel-health-endpoints.app_info.enabled' => false]);
    $this->getJson('/lhe/v1/ping')->assertOk()->assertJsonMissingPath('app');

    config(['laravel-health-endpoints.app_info.enabled' => true, 'laravel-health-endpoints.details' => 'never']);
    $this->getJson('/lhe/v1/ping')->assertOk()->assertJsonMissingPath('app');
});

it('includes app info in reports only when detailed', function () {
    config(['laravel-health-endpoints.details' => 'always', 'app.name' => 'Acme']);
    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('app', ['name' => 'Acme', 'environment' => 'testing', 'version' => null]);

    config(['laravel-health-endpoints.details' => 'never']);
    $this->getJson('/lhe/v1')->assertOk()->assertJsonMissingPath('app');
});

it('serves custom routes', function () {
    config(['laravel-health-endpoints.details' => 'always']);

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
    config(['laravel-health-endpoints.details' => 'always', 'laravel-health-endpoints.cache_ttl' => 10]);

    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('cached', true);
    $this->getJson('/lhe/v1/two-target-fixture')->assertOk()->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk()->assertJsonPath('cached', false);
    $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk()->assertJsonPath('cached', true);
});

it('keeps the http status for cached failures', function () {
    config(['laravel-health-endpoints.details' => 'always', 'laravel-health-endpoints.cache_ttl' => 10]);
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

    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertNotFound()->assertExactJson(['status' => 'fail', 'message' => 'Not found']);
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
