# Laravel Health Endpoints Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `lexo/laravel-health-endpoints` Composer package: secure JSON health-check endpoints under `/lhe/v1`, eight built-in checks, frontend markers, state-transition logging/events, and a first-class extension API for custom checks.

**Architecture:** A service provider registers config, a `HealthCheckRegistry` (built-in + discovered + configured + programmatic checks), routes guarded by package-owned middleware (headers → throttle → IP allowlist → token), and a `HealthRunner` that executes checks (timing, sanitized failures, `StateTracker` logging/events). Results become an immutable `HealthReport` that renders to minimal or detailed JSON and is cached briefly by `ResultCache`. Markers are injected by a `web`-group middleware.

**Tech Stack:** PHP ^8.5, Laravel 13 components (`illuminate/*`), Pest 4 + Orchestra Testbench 11, Larastan (PHPStan max), Laravel Pint, predis (dev, for Redis tests), semantic-release.

**Spec:** `docs/superpowers/specs/2026-10-30-laravel-health-endpoints-design.md` — read it alongside this plan; it is the source of truth for behaviour, messages, env names and defaults.

## Global Constraints

- `"php": "^8.5"`; runtime deps only `illuminate/*` `^13.0` (support, contracts, http, routing, console, cache, log, events, queue, database, redis, filesystem, view). Dev: `orchestra/testbench ^11`, `pestphp/pest ^4`, `pestphp/pest-plugin-laravel ^4`, `larastan/larastan ^3`, `laravel/pint ^1`, `predis/predis ^2`.
- Package `lexo/laravel-health-endpoints`, namespace `LEXO\LaravelHealthEndpoints\` → `src/`, tests `LEXO\LaravelHealthEndpoints\Tests\` → `tests/`. Provider `LEXO\LaravelHealthEndpoints\Providers\LaravelHealthEndpointsServiceProvider` auto-discovered via `extra.laravel.providers`.
- Config key `laravel-health-endpoints`; publish tags `laravel-health-endpoints-config`, `laravel-health-endpoints-stubs`.
- Route prefix `lhe/v1` and built-in names are fixed. Reserved names: `ping`, `database`, `redis`, `cache`, `storage`, `queue`, `scheduler`, `migrations`, `debug-mode`.
- Every env var is read only inside `config/laravel-health-endpoints.php` (never `env()` in `src/`), so `config:cache` works.
- Security first: every task lists security considerations; secure defaults; no secrets in responses/logs; tests for security behaviour.
- `declare(strict_types=1);` in every PHP file; `final` for classes not meant for extension; PHPDoc array shapes on public APIs.
- Commits: the user commits only on explicit request. Each task ends with a checkpoint; run the commit command only if the user has authorized commits for this execution. Never add `Co-Authored-By` or any attribution lines.
- Quality gate after every task: `vendor/bin/pest`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test` all green.

## Review Focus

1. **Malformed token input** — `?token[]=x`, an empty `Authorization: Bearer `, or both header and query present → no TypeError/500; non-string or empty values count as missing → `401`; header wins when both present. (Task 6)
2. **Hostile or odd path segments** — targets like `mysql.read`, `redis:cache` work when configured; `..%2F`, encoded slashes, unconfigured names, over-long segments → JSON `404` (after auth), never a connection/disk/file lookup. (Task 6, Task 7)
3. **Sloppy env values** — `LHE_DB_CONNECTIONS=" mysql , reporting ,"`, `LHE_CACHE_TTL=""`, `LHE_RATE_LIMIT="abc"`, `LHE_DETAILS="AUTO"` → trimmed, empties dropped, invalid numbers fall back to spec defaults, enum values case-insensitive with `auto` fallback. (Task 2)
4. **Package's own storage unavailable** — `LHE_CACHE_STORE` points to an unwritable/missing store, heartbeat store throws → endpoints still answer with correct check results; no 500, no exception page. (Task 5, Task 9)
5. **Unusual HTML** — `<HEAD lang="x">`, `</BODY>` uppercase, `</body>` appearing inside an inline script before the real one, `Content-Encoding: gzip` responses, `204`/redirect HTML → markers inserted after first `<head…>` and before the **last** `</body>`; compressed or non-2xx responses untouched. (Task 11)

---

## File Structure

```
composer.json, phpunit.xml, phpstan.neon, pint.json, .gitignore
config/laravel-health-endpoints.php
stubs/health-check.stub, stubs/health-check.targets.stub
resources/boost/guidelines/core.blade.php
src/
  Providers/LaravelHealthEndpointsServiceProvider.php   wiring only
  Facades/LaravelHealthEndpoints.php                    → HealthCheckRegistry
  Contracts/{HealthCheck,HasTargets,HasCustomRoute}.php
  Checks/Check.php, Checks/TargetedCheck.php            base classes
  Checks/{Database,Redis,Cache,Storage,Queue,Scheduler,Migrations,DebugMode}Check.php
  Registry/HealthCheckRegistry.php, Registry/HealthCheckDiscovery.php
  Runner/HealthRunner.php, Runner/StateTracker.php
  Support/{Status,CheckResult,CheckReport,HealthReport,Settings,MessageSanitizer,ResultCache,Heartbeats}.php
  Exceptions/{InvalidHealthCheckException,TargetNotFoundException}.php
  Events/{HealthCheckFailed,HealthCheckRecovered}.php
  Jobs/QueueHeartbeatJob.php
  Http/routes.php
  Http/Controllers/HealthController.php
  Http/Middleware/{SecureHealthHeaders,ThrottleHealthRequests,RestrictHealthIps,AuthorizeHealthToken,InjectServiceMarkers}.php
  Console/{MakeHealthCheckCommand,ListHealthRoutesCommand,RunHealthChecksCommand,GenerateTokenCommand}.php
tests/Pest.php, tests/TestCase.php, tests/Fixtures/…, tests/Unit/…, tests/Feature/…
README.md, SECURITY.md, CHANGELOG.md, .releaserc, package.json
.github/workflows/{ci,release}.yml, .github/dependabot.yml
```

---

### Task 1: Package scaffold, config, provider skeleton, tooling

**Files:**
- Create: `composer.json`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `.gitignore`, `config/laravel-health-endpoints.php`, `src/Providers/LaravelHealthEndpointsServiceProvider.php`, `tests/Pest.php`, `tests/TestCase.php`, `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Produces: `TestCase` (extends `Orchestra\Testbench\TestCase`, registers the provider, sets `app.key`); config array exactly as spec "Config file sketch" plus `discover.namespace => env('LHE_DISCOVER_NAMESPACE', 'App\\HealthChecks')` (builder's call: discovery needs an explicit namespace).

**Security:** config holds the token — never dump config in any output; `.gitignore` excludes `.env`, `vendor/`, `node_modules/`, `.phpunit.cache`.

- [ ] **Step 1: Write `composer.json`** per Global Constraints (mirror `lexo/laravel-log-monitor` layout: `type: library`, MIT, `scripts.test = vendor/bin/pest`, `scripts.analyse = vendor/bin/phpstan analyse`, `scripts.format = vendor/bin/pint`, `allow-plugins.pestphp/pest-plugin: true`, `prefer-stable: true`, `config.platform.php: 8.5.0`). Run `composer install`. Expected: success.
- [ ] **Step 2: Write the failing test** `tests/Feature/ServiceProviderTest.php`:

```php
it('merges the package config', fn () =>
    expect(config('laravel-health-endpoints.cache_ttl'))->toBe(10)
        ->and(config('laravel-health-endpoints.details'))->toBe('auto')
        ->and(config('laravel-health-endpoints.markers.header'))->toBe('<template id="lhe-service-up-header">UP</template>'));

it('registers the config publish tag', fn () =>
    expect(ServiceProvider::pathsToPublish(LaravelHealthEndpointsServiceProvider::class, 'laravel-health-endpoints-config'))
        ->toHaveCount(1));
```

- [ ] **Step 3: Run** `vendor/bin/pest tests/Feature/ServiceProviderTest.php` → FAIL (provider/config missing).
- [ ] **Step 4: Implement** the config file (spec sketch + `discover.namespace`) and the provider: `register()` merges config; `boot()` publishes config and `stubs/` (tag `laravel-health-endpoints-stubs` → `base_path('stubs/lhe')`). `phpunit.xml` copied from the reference; `phpstan.neon` includes Larastan, `level: max`, paths `src`, `config`; `pint.json` preset `laravel`.
- [ ] **Step 5: Run** `vendor/bin/pest && vendor/bin/phpstan analyse && vendor/bin/pint --test` → all green.
- [ ] **Step 6: Checkpoint** — `git add -A && git commit -m "chore: scaffold package, config and service provider"` (only if authorized).

---

### Task 2: Core types, settings and message sanitizer

**Files:**
- Create: `src/Support/Status.php`, `src/Support/CheckResult.php`, `src/Support/Settings.php`, `src/Support/MessageSanitizer.php`, `src/Contracts/HealthCheck.php`, `src/Contracts/HasTargets.php`, `src/Contracts/HasCustomRoute.php`, `src/Checks/Check.php`, `src/Checks/TargetedCheck.php`
- Test: `tests/Unit/CheckResultTest.php`, `tests/Unit/CheckTest.php`, `tests/Unit/SettingsTest.php`, `tests/Unit/MessageSanitizerTest.php`

**Interfaces:**
- Produces:
  - `enum Status: string { case Ok = 'ok'; case Fail = 'fail'; }`
  - `final readonly class CheckResult { __construct(Status $status, ?string $message = null, array $meta = []); static ok(?string $message = null, array $meta = []): self; static fail(string $message, array $meta = []): self; isOk(): bool }`
  - Contracts exactly as spec "Contracts".
  - `abstract class Check implements HealthCheck { protected ?string $name = null; public function name(): string }` — default name: class basename minus trailing `Check`, kebab-case.
  - `abstract class TargetedCheck extends Check implements HasTargets { public function run(): CheckResult }` — runs every target, returns `fail` with message `"{n} of {total} targets failed"` if any fail, else `ok`.
  - `final class Settings` (container singleton, reads config once per request): `token(): ?string` (null when empty), `allowedIps(): list<string>`, `isProtected(): bool`, `detailed(): bool`, `rateLimit(): int`, `timeout(): int`, `cacheTtl(): int`, `cacheStore(): string`, `heartbeatStore(): ?string`, `list(string $key): list<string>` (CSV → trimmed, non-empty, unique), `int(string $key, int $default, int $min = 0): int`.
  - `final class MessageSanitizer { static sanitize(string $message): string; static fromThrowable(Throwable $e): string }`

**Security:** this task defines what may leave the process. `detailed()`: `always` → true, `never` → false, anything else (`auto`, unknown, any case) → `isProtected()`.

- [ ] **Step 1: Write failing tests**

```php
// CheckTest
it('derives kebab names', fn () => expect((new DiskSpaceCheck)->name())->toBe('disk-space'));   // tests/Fixtures/Checks/DiskSpaceCheck.php
it('allows name override', fn () => expect((new NamedFixtureCheck)->name())->toBe('custom-name'));
it('targeted run fails if any target fails', fn () =>
    expect((new TwoTargetFixture(['a' => true, 'b' => false]))->run())
        ->status->toBe(Status::Fail)->message->toBe('1 of 2 targets failed'));

// SettingsTest  (Review Focus 3)
it('parses sloppy csv', function () {
    config(['laravel-health-endpoints.database.connections' => ' mysql , reporting ,,mysql']);
    expect(app(Settings::class)->list('database.connections'))->toBe(['mysql', 'reporting']);
});
it('falls back on invalid ints', function () {
    config(['laravel-health-endpoints.rate_limit' => 'abc', 'laravel-health-endpoints.cache_ttl' => '']);
    expect(app(Settings::class))->rateLimit()->toBe(60)->cacheTtl()->toBe(10);
});
it('resolves details mode', function (string $mode, ?string $token, bool $expected) {
    config(['laravel-health-endpoints.details' => $mode, 'laravel-health-endpoints.token' => $token ?? '']);
    expect(app(Settings::class)->detailed())->toBe($expected);
})->with([['auto', null, false], ['AUTO', 'secret', true], ['never', 'secret', false], ['always', null, true], ['bogus', null, false]]);

// MessageSanitizerTest
it('strips secrets', fn (string $in, string $out) => expect(MessageSanitizer::sanitize($in))->toBe($out))->with([
    ['redis://user:p4ss@10.0.0.1:6379 refused', 'redis://***@10.0.0.1:6379 refused'],
    ['host=db;password=hunter2;port=5432', 'host=db;password=***;port=5432'],
    ['failed in /var/www/app/vendor/x/Foo.php:12', 'failed in [path]'],
    ["line1\nline2", 'line1'],
]);
it('truncates to 255 chars', fn () => expect(strlen(MessageSanitizer::sanitize(str_repeat('a', 400))))->toBe(255));
it('maps known failures', fn (string $raw, string $safe) =>
    expect(MessageSanitizer::fromThrowable(new PDOException($raw)))->toBe($safe))->with([
    ['SQLSTATE[HY000] [2002] Connection refused', 'Connection refused'],
    ["SQLSTATE[HY000] [1045] Access denied for user 'app'@'10.0.0.5'", 'Authentication failed'],
    ['FATAL: password authentication failed for user "app"', 'Authentication failed'],
    ['Operation timed out', 'Timeout'],
    ["SQLSTATE[HY000] [1049] Unknown database 'x'", 'Database not found'],
]);
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Unit` → FAIL.
- [ ] **Step 3: Implement** the types. Sanitizer rules (in order): first line only; `scheme://user:pass@` → `scheme://***@`; `(password|passwd|pwd|secret|token|key)=value` → `=***` (case-insensitive, value up to `;`, `&`, space); absolute paths ending `.php` (optionally `:line`), Unix and Windows → `[path]`; trim; `mb_strimwidth` to 255. `fromThrowable` matches (case-insensitive) `connection refused|no such host|could not translate host|getaddrinfo` → `Connection refused`; `access denied|authentication failed|noauth|wrongpass` → `Authentication failed`; `timed out|timeout` → `Timeout`; `unknown database|does not exist` → `Database not found`; else `sanitize($e->getMessage())`, empty → `Check failed`.
- [ ] **Step 4: Run** tests + phpstan + pint → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add core result types, settings and message sanitizer"` (if authorized).

---

### Task 3: Registry, discovery, facade, name validation

**Files:**
- Create: `src/Registry/HealthCheckRegistry.php`, `src/Registry/HealthCheckDiscovery.php`, `src/Facades/LaravelHealthEndpoints.php`, `src/Exceptions/InvalidHealthCheckException.php`
- Modify: provider (bind registry singleton; register built-ins lazily by class name — built-in classes are created in Tasks 7–10, so this task registers a `BUILT_IN` map `name => [class, configKey]` and skips classes that don't exist yet)
- Test: `tests/Feature/RegistryTest.php`, fixtures `tests/Fixtures/HealthChecks/*.php`

**Interfaces:**
- Consumes: `HealthCheck`, `HasCustomRoute` (Task 2).
- Produces:
  - `HealthCheckRegistry::register(string|HealthCheck $check): void` (class-string resolved through the container — constructor injection works)
  - `HealthCheckRegistry::all(): array<string, HealthCheck>` — enabled checks keyed by name, built-ins first, then config `checks`, discovered, programmatic; validated on first call and memoized.
  - `HealthCheckRegistry::find(string $name): ?HealthCheck`
  - `HealthCheckRegistry::customRoutes(): array<string, string>` — path ⇒ check name.
  - `HealthCheckRegistry::RESERVED: list<string>` (the nine reserved names).
  - `HealthCheckDiscovery::discover(string $directory, string $namespace): list<class-string<HealthCheck>>` — PSR-4 mapping of `*.php` files under the directory; only instantiable classes implementing `HealthCheck`; missing directory → `[]`. No caching (builder's call: one small directory scan per health request is negligible).
  - Facade `LaravelHealthEndpoints` → `HealthCheckRegistry`.

**Security:** class names come only from config, discovery dir, or code — never from requests. Validation messages name the class and the fix.

- [ ] **Step 1: Write failing tests**

```php
it('discovers checks in the configured directory', function () {
    config(['laravel-health-endpoints.discover.path' => 'tests/Fixtures/HealthChecks',     // relative to base_path; TestCase sets base path to package root for this test
            'laravel-health-endpoints.discover.namespace' => 'LEXO\\LaravelHealthEndpoints\\Tests\\Fixtures\\HealthChecks']);
    expect(app(HealthCheckRegistry::class)->all())->toHaveKey('disk-space')->not->toHaveKey('abstract-fixture');
});
it('registers from config and facade', function () {
    config(['laravel-health-endpoints.checks' => [ConfigFixtureCheck::class]]);
    LaravelHealthEndpoints::register(FacadeFixtureCheck::class);
    expect(app(HealthCheckRegistry::class)->all())->toHaveKeys(['config-fixture', 'facade-fixture']);
});
it('rejects reserved names', fn () => /* fixture named "redis" */)->throws(InvalidHealthCheckException::class, 'reserved');
it('rejects duplicates', fn () => /* two fixtures named "dup" */)->throws(InvalidHealthCheckException::class, 'already registered');
it('rejects invalid names', fn () => /* name "Bad_Name" */)->throws(InvalidHealthCheckException::class, '[a-z0-9-]+');
it('rejects custom paths that collide', fn () => /* path "database/x" */)->throws(InvalidHealthCheckException::class);
it('omits disabled built-ins', function () {
    config(['laravel-health-endpoints.redis.enabled' => false]);
    expect(app(HealthCheckRegistry::class)->find('redis'))->toBeNull();
});
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Name rule `^[a-z0-9]+(-[a-z0-9]+)*$`; custom path rule `^[a-z0-9-]+(/[a-z0-9-]+)*$`, first segment must not be reserved or another check's name. Enabled flags cast with `filter_var(..., FILTER_VALIDATE_BOOL)`.
- [ ] **Step 4: Run** tests + phpstan + pint → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add check registry, discovery and facade"` (if authorized).

---

### Task 4: Runner, state tracking, logging, events

**Files:**
- Create: `src/Runner/HealthRunner.php`, `src/Runner/StateTracker.php`, `src/Support/CheckReport.php`, `src/Support/HealthReport.php`, `src/Exceptions/TargetNotFoundException.php`, `src/Events/HealthCheckFailed.php`, `src/Events/HealthCheckRecovered.php`
- Test: `tests/Feature/RunnerTest.php`, `tests/Feature/StateTrackerTest.php`

**Interfaces:**
- Consumes: Task 2 types, `Settings`.
- Produces:
  - `final readonly class CheckReport { __construct(string $name, Status $status, float $durationMs, ?string $message = null, array $meta = [], ?array $targets = null /* array<string, CheckReport> */); toArray(bool $detailed): array; static fromArray(string $name, array $data): self }`
  - `final readonly class HealthReport { __construct(array $checks /* array<string, CheckReport> */, CarbonImmutable $timestamp, float $durationMs, bool $cached = false); status(): Status; httpStatus(): int /* 200|503 */; toArray(bool $detailed, ?array $app): array; toCacheArray(): array; static fromCacheArray(array $data): self /* cached = true */ }`
  - `HealthRunner::runCheck(HealthCheck $check, ?string $target = null): CheckReport` — throws `TargetNotFoundException` when `$target` given and not in `targets()` (strict `in_array`), or when check has no targets.
  - `HealthRunner::runAll(iterable $checks): HealthReport` (sequential)
  - `StateTracker::record(string $check, ?string $target, CheckResult $result, float $durationMs, ?Throwable $exception = null): void`
  - Events: readonly public `string $check`, `?string $target`, `CheckResult $result`, `float $durationMs`.

**Rendering rules (from spec):** detailed → full shape of spec example (message/meta/targets, durations rounded to int ms). Minimal → top level `status`, `timestamp` (ISO-8601), `checks.{name}.status` only — no `message`, `meta`, `targets`, `duration_ms`, `cached`, `app` (builder's call resolving the spec's "per-target status" vs "no target names": target-level output exists only in detailed mode). `app` (`name`, `environment`, `version`) added only when detailed and `app_info.enabled`.

**Security:** every message (thrown or returned via `CheckResult::fail`) passes through `MessageSanitizer`; raw exception goes only into the log context `exception`. Log context never contains request data or the token.

- [ ] **Step 1: Write failing tests**

```php
it('measures and catches exceptions', function () {
    $report = app(HealthRunner::class)->runCheck(new ThrowingFixtureCheck('redis://u:p@h refused'));
    expect($report)->status->toBe(Status::Fail)->message->toBe('Connection refused')->durationMs->toBeGreaterThan(0);
});
it('sanitizes returned fail messages', fn () =>
    expect(app(HealthRunner::class)->runCheck(new FailingFixtureCheck('password=x'))->message)->toBe('password=***'));
it('rejects unknown targets', fn () => app(HealthRunner::class)->runCheck(new TwoTargetFixture(['a' => true]), 'zzz'))
    ->throws(TargetNotFoundException::class);
it('aggregates to 503 when any check fails', fn () =>
    expect(app(HealthRunner::class)->runAll([new OkFixtureCheck, new FailingFixtureCheck('x')])->httpStatus())->toBe(503));
it('renders minimal output without details', function () {
    $arr = app(HealthRunner::class)->runAll([new TwoTargetFixture(['a' => false])])->toArray(false, ['name' => 'X']);
    expect($arr)->toHaveKeys(['status', 'timestamp', 'checks'])->not->toHaveKeys(['app', 'duration_ms'])
        ->and($arr['checks']['two-target-fixture'])->toBe(['status' => 'fail']);
});

// StateTrackerTest
it('logs one error per outage and info with llm on recovery', function () {
    Log::spy(); Event::fake();
    $t = app(StateTracker::class);
    $t->record('redis', 'default', CheckResult::fail('down'), 1.0);
    $t->record('redis', 'default', CheckResult::fail('down'), 1.0);
    $t->record('redis', 'default', CheckResult::ok(), 1.0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => $m === '[LHE] redis:default check failed: down');
    Log::shouldHaveReceived('info')->once()->withArgs(fn ($m, $c) => $m === '[LHE] redis:default check recovered' && $c['llm']['alert'] === true);
    Event::assertDispatchedTimes(HealthCheckFailed::class, 1);
    Event::assertDispatchedTimes(HealthCheckRecovered::class, 1);
});
it('adds llm channel and priority to failures', function () { /* config log.llm_channel=monitoring, llm_priority=urgent → error context llm = ['channel'=>'monitoring','priority'=>'urgent'] (no alert key) */ });
it('re-logs still failing checks after remind_minutes', function () { /* remind_minutes=5, travel 6 minutes → second error */ });
it('never throws when the state store fails', function () { /* cache_store pointing to an undefined store → record() returns normally */ });
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Timing via `hrtime(true)`. State key `lhe:state:` + `sha1("$check|$target")`, value `['status' => 'ok|fail', 'last_logged' => int]`, stored `forever` in `Settings::cacheStore()`. Unknown previous state = `ok`. All store access in `try/catch (Throwable)`. Log via `Log::channel($channel ?: null)`; `llm` built with `array_filter` per spec (recovery: `alert => true` + channel; failure: channel + priority).
- [ ] **Step 4: Run** tests + phpstan + pint → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add runner, reports and state-transition logging"` (if authorized).

---

### Task 5: Result cache

**Files:**
- Create: `src/Support/ResultCache.php`
- Test: `tests/Feature/ResultCacheTest.php`

**Interfaces:**
- Consumes: `HealthReport::toCacheArray/fromCacheArray`, `Settings`.
- Produces: `ResultCache::remember(string $scope, Closure $run): HealthReport` — `$scope` is built by the controller from **resolved** values (`'all'`, `"check:$name"`, `"check:$name:$target"`), hashed into key `lhe:result:` + `sha1($scope)`. TTL `Settings::cacheTtl()`; `0` → always runs. Any store exception → run uncached.

**Security:** keys never include raw input or the token; cached data is the full report, minimization happens at render time per request.

- [ ] **Step 1: Write failing tests** — `returns cached report within ttl` (closure called once over two calls, second `cached === true`); `expires after ttl` (`$this->travel(11)->seconds()`); `ttl 0 disables`; `broken store falls back` (Review Focus 4: `cache_store = 'missing-store'` → closure result returned, no exception).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add short-lived result cache"` (if authorized).

---

### Task 6: HTTP layer — routes, controller, security middleware

**Files:**
- Create: `src/Http/routes.php`, `src/Http/Controllers/HealthController.php`, `src/Http/Middleware/SecureHealthHeaders.php`, `src/Http/Middleware/ThrottleHealthRequests.php`, `src/Http/Middleware/RestrictHealthIps.php`, `src/Http/Middleware/AuthorizeHealthToken.php`
- Modify: provider (`loadRoutesFrom`, after app booted so custom routes are known)
- Test: `tests/Feature/Http/EndpointsTest.php`, `tests/Feature/Http/SecurityTest.php`

**Interfaces:**
- Consumes: registry, runner, `ResultCache`, `Settings`.
- Produces routes (all `Route::match(['GET','HEAD'])`, prefix `lhe/v1`, names `lhe.*`, middleware in this order: `SecureHealthHeaders`, `ThrottleHealthRequests`, `RestrictHealthIps`, `AuthorizeHealthToken`; no `web`/`api` group):
  - `lhe.all` `/lhe/v1`, `lhe.ping` `/lhe/v1/ping`
  - one route per custom path (`lhe.custom.{name}`) registered **before** the generic ones
  - `lhe.check` `/lhe/v1/{check}/{target?}` with `check` `[a-z0-9-]+` and `target` `[A-Za-z0-9_.:-]{1,128}`
  - `lhe.fallback` `Route::any('/lhe/v1/{any}')->where('any', '.*')` → after auth: `405` for non-GET/HEAD, else `404`.
- JSON bodies for errors: `{"status":"fail","message":"Unauthorized|Forbidden|Not found|Method not allowed|Too many requests"}`.

**Behaviour:**
- Controller wraps everything in `try/catch (Throwable)` → `503` `{"status":"fail","message":"Health check error"}` + `Log::error` with exception; never the debug page.
- `ping` → `{"status":"ok","timestamp":…}` (+ `app` when detailed), no cache, no checks.
- Throttle: `RateLimiter::attempt('lhe:'.sha1((string) $request->ip()), Settings::rateLimit(), …, 60)`; on limit → `429` + `Retry-After`.
- IP: `IpUtils::checkIp((string) $request->ip(), Settings::allowedIps())` when list non-empty; null IP → deny.
- Token: provided = `$request->bearerToken()` if non-empty string, else `$request->query('token')` if `is_string` and non-empty, else null; `hash_equals($token, $provided)`.
- Headers on every package response (including 401/403/404/405/429): `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex, nofollow`, `Content-Type: application/json`.
- Production public warning: when `app()->isProduction()` and `!Settings::isProtected()`, `Cache::store(cacheStore)->add('lhe:public-warning', true, 86400)` gates one `Log::warning('[LHE] Health endpoints are public; responses are minimal. Set LHE_TOKEN or LHE_ALLOWED_IPS.')` per 24 h (builder's call for spec's "per deploy"); store failures swallowed.

**Security:** this task is the access-control boundary — every test below is mandatory.

- [ ] **Step 1: Write failing tests** (register fixture checks via facade in `beforeEach`)

```php
// EndpointsTest
it('returns 200 when all pass', fn () => $this->getJson('/lhe/v1')->assertOk()->assertJsonPath('status', 'ok'));
it('returns 503 when any fails', /* failing fixture */);
it('runs a single check and target', fn () => $this->getJson('/lhe/v1/two-target-fixture/a')->assertOk());
it('404s unknown check and target', function () {
    $this->getJson('/lhe/v1/nope')->assertNotFound()->assertExactJson(['status' => 'fail', 'message' => 'Not found']);
    $this->getJson('/lhe/v1/two-target-fixture/zzz')->assertNotFound();
});
it('404s traversal-like targets', fn (string $p) => $this->getJson($p)->assertNotFound())  // Review Focus 2
    ->with(['/lhe/v1/two-target-fixture/..%2F..%2Fetc', '/lhe/v1/two-target-fixture/a/b', '/lhe/v1/two-target-fixture/'.str_repeat('a', 200)]);
it('answers HEAD with same status and empty body', fn () => $this->call('HEAD', '/lhe/v1')->assertOk()->assertContent(''));
it('405s other methods as JSON', fn () => $this->postJson('/lhe/v1')->assertStatus(405)->assertJsonPath('message', 'Method not allowed'));
it('pings without running checks', /* failing fixture registered, /lhe/v1/ping still 200 */);
it('serves custom routes', fn () => $this->getJson('/lhe/v1/billing/stripe')->assertOk());
it('serves cached reports', /* second call cached: true when detailed */);
it('never shows the debug page', function () {
    config(['app.debug' => true]);
    /* bind registry that throws */ $this->getJson('/lhe/v1')->assertStatus(503)->assertJsonMissingPath('exception');
});

// SecurityTest
it('requires the token when set', function () {
    config(['laravel-health-endpoints.token' => 'secret-token']);
    $this->getJson('/lhe/v1')->assertUnauthorized();
    $this->getJson('/lhe/v1?token=secret-token')->assertOk();
    $this->getJson('/lhe/v1', ['Authorization' => 'Bearer secret-token'])->assertOk();
});
it('treats malformed tokens as missing', fn (string $uri, array $h) => $this->getJson($uri, $h)->assertUnauthorized())  // Review Focus 1
    ->with([['/lhe/v1?token[]=secret-token', []], ['/lhe/v1', ['Authorization' => 'Bearer ']], ['/lhe/v1?token=secret-token', ['Authorization' => 'Bearer wrong']]]);
it('authenticates before 404', /* token set, /lhe/v1/nope without token → 401 */);
it('enforces the IP allowlist', fn (string $ip, int $status) =>
    $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/lhe/v1')->assertStatus($status))
    ->with([['10.1.2.3', 200], ['192.168.1.5', 403], ['2001:db8::1', 200]]);  // allowed_ips = '10.0.0.0/8, 2001:db8::/32'
it('ignores X-Forwarded-For without trusted proxies', /* REMOTE_ADDR 1.2.3.4 + XFF 10.0.0.1 → 403 */);
it('rate limits', /* rate_limit=2 → third request 429 with Retry-After */);
it('sends security headers on every response', fn (string $uri) =>
    $this->getJson($uri)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private'))->with(['/lhe/v1', '/lhe/v1/nope']);
it('sets no cookies and no CORS headers', /* assertCookieMissing for app session cookie, header Access-Control-Allow-Origin missing */);
it('returns minimal output when unprotected', /* no token/ips → no message/meta/app keys */);
it('never logs or caches the token', /* Log::spy; failing check with token set → no logged context contains 'secret-token'; cache keys don't contain it */);
it('warns once per day in production when public', /* env production, two requests → one Log::warning */);
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** routes, controller (`all`, `ping`, `check`, `custom`, `fallback`), four middleware.
- [ ] **Step 4: Run** tests + phpstan + pint → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add secured health endpoints"` (if authorized).

---

### Task 7: Database and Redis checks

**Files:**
- Create: `src/Checks/DatabaseCheck.php`, `src/Checks/RedisCheck.php`
- Test: `tests/Feature/Checks/DatabaseCheckTest.php`, `tests/Feature/Checks/RedisCheckTest.php`

**Interfaces:**
- Consumes: `TargetedCheck`, `Settings::list()`, `Settings::timeout()`.
- Produces: `DatabaseCheck` (name `database`), `RedisCheck` (name `redis`), both `TargetedCheck`s, registered in the provider's `BUILT_IN` map.

**Behaviour:**
- Database targets: `list('database.connections')` or `[config('database.default')]`. Unconfigured connection → `fail('Connection not configured')`. Probe on a throwaway connection `lhe_probe_{name}` = copy of the connection config plus driver-specific connect timeout (mysql/mariadb `options[PDO::ATTR_TIMEOUT]`, pgsql `connect_timeout`, sqlsrv `login_timeout`; sqlite none), `select 1`, `meta = ['driver' => …, 'version' => getServerVersion()]`, then `DB::purge` the probe connection in `finally`. The app's own connection is never touched.
- Redis targets: `list('redis.connections')` or `['default']`. Unconfigured → `fail('Connection not configured')`. Probe via a throwaway `Illuminate\Redis\RedisManager` built from `config('database.redis')` with the target's config cloned and `timeout`/`read_timeout` = `Settings::timeout()`; `ping()`; `meta = ['client' => …, 'version' => info server version if available]`; disconnect in `finally`.

**Security:** only configured connection names are probed; DB probe is `select 1` only; messages via sanitizer (runner).

- [ ] **Step 1: Write failing tests** — DB: `checks the default connection` (sqlite `:memory:` → ok, `meta.driver === 'sqlite'`); `checks multiple connections` (two sqlite connections via `LHE_DB_CONNECTIONS`, endpoint `/lhe/v1/database` detailed has both targets); `single connection route` (`/lhe/v1/database/second` ok); `unconfigured connection fails`; `unreachable mysql fails fast with safe message` (host `127.0.0.1`, port `1`, timeout 1 → fail `Connection refused`, duration < 3000 ms, message contains no host/password); `does not purge the app connection` (`DB::connection()` same PDO instance before/after). Redis: `unreachable redis fails fast` (port 1); `pings a live redis` (skip unless `REDIS_HOST` reachable — CI provides service); `unconfigured connection fails`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → green (live-Redis test skipped locally if no Redis).
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add database and redis checks"` (if authorized).

---

### Task 8: Cache and storage checks

**Files:**
- Create: `src/Checks/CacheCheck.php`, `src/Checks/StorageCheck.php`
- Test: `tests/Feature/Checks/CacheCheckTest.php`, `tests/Feature/Checks/StorageCheckTest.php`

**Interfaces:**
- Produces: `CacheCheck` (`cache`, targets `list('cache.stores')` or `[config('cache.default')]`), `StorageCheck` (`storage`, targets `list('storage.disks')` or `[config('filesystems.default')]`).

**Behaviour:** cache: key `lhe:probe:` + `Str::random(16)`, put value `Str::random(32)` TTL 10 s, read back, mismatch → `fail('Read-back mismatch')`, `forget` in `finally`. Storage: path `.lhe-health/` + `Str::random(32)` + `.txt`, write/read/compare, `delete` in `finally`. Unconfigured store/disk → `fail('Store not configured')` / `fail('Disk not configured')`.

**Security:** random names only; cleanup in `finally` even on failure; never a disk/store outside the configured list.

- [ ] **Step 1: Write failing tests** — `cache passes on array store`; `cache cleans up on mismatch` (store spy where `get` returns other value → fail, `forget` called); `storage passes on fake disk and leaves no files` (`Storage::fake('local')`, `allFiles('.lhe-health')` empty after); `storage cleans up when read fails` (mock disk: `put` ok, `get` throws → fail, `delete` called); unconfigured target fails.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add cache and storage checks"` (if authorized).

---

### Task 9: Scheduler and queue heartbeat checks

**Files:**
- Create: `src/Support/Heartbeats.php`, `src/Checks/SchedulerCheck.php`, `src/Checks/QueueCheck.php`, `src/Jobs/QueueHeartbeatJob.php`
- Modify: provider (`callAfterResolving(Schedule::class, …)`)
- Test: `tests/Feature/Checks/SchedulerCheckTest.php`, `tests/Feature/Checks/QueueCheckTest.php`

**Interfaces:**
- Produces:
  - `Heartbeats::touch(string $key): void`, `Heartbeats::last(string $key): ?CarbonImmutable` — store `Settings::heartbeatStore()` (null → default), values are unix ints stored `forever`; read failures → treat as missing.
  - Keys: `lhe:heartbeat:scheduler`, `lhe:heartbeat:queue:` + `sha1($target)`.
  - `QueueHeartbeatJob(string $target)` implements `ShouldQueue`, `$tries = 1`, `handle(Heartbeats $h)` touches the target key.
  - Schedule (only when the check is enabled): `$schedule->call(fn () => app(Heartbeats::class)->touch('lhe:heartbeat:scheduler'))->everyMinute()->name('lhe:scheduler-heartbeat')`; per non-sync/null queue target `$schedule->job(new QueueHeartbeatJob($target), $queue, $connection)->everyMinute()->name("lhe:queue-heartbeat:$target")`.
  - Queue target parsing: `"conn"` or `"conn:queue"`; queue default `config("queue.connections.$conn.queue", 'default')`; default target `config('queue.default')`.

**Messages (exact):** scheduler missing → `No scheduler heartbeat yet — is schedule:run / schedule:work running?`; stale → `Last scheduler heartbeat {n} min ago (max {max})`; queue missing → `No queue heartbeat yet — are the scheduler and a queue worker running?`; stale → `Last queue heartbeat {n} min ago (max {max})`; sync → `ok('sync driver — no worker required')`; null → `ok('null driver')`; unconfigured → `fail('Connection not configured')`. `meta.last_heartbeat` ISO-8601 when present.

**Security:** job payload is the target string only; heartbeat values are timestamps only.

- [ ] **Step 1: Write failing tests** — scheduler: missing → fail message; fresh (touch then check) → ok; stale (`travel(3)->minutes()`, max 2) → fail; schedule contains `lhe:scheduler-heartbeat` event when enabled and not when disabled. Queue: sync → ok; null → ok; database driver with no heartbeat → fail; job handle writes heartbeat → ok; stale after 6 min → fail; `conn:queue` parsing dispatches to that queue (`Queue::fake()` + run schedule event); heartbeat store throwing → fail "No … heartbeat yet" not exception (Review Focus 4).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add scheduler and queue heartbeat checks"` (if authorized).

---

### Task 10: Migrations and debug-mode checks

**Files:**
- Create: `src/Checks/MigrationsCheck.php`, `src/Checks/DebugModeCheck.php`
- Test: `tests/Feature/Checks/MigrationsCheckTest.php`, `tests/Feature/Checks/DebugModeCheckTest.php`

**Behaviour:** migrations (`migrations`): migrator `app('migrator')`; `!repositoryExists()` → `fail('Migrations table not found')`; pending = files from `database_path('migrations')` + `$migrator->paths()` minus `getRepository()->getRan()`; pending → `fail("{n} pending migration(s): a, b, c")` (first 3 names, `…` if more), `meta.pending = n`; else `ok`. Debug mode (`debug-mode`): `config('app.debug')` true and `app()->environment(list('debug_mode.forbidden_environments'))` → `fail('APP_DEBUG is enabled in {env}')`; else `ok`, `meta.debug = bool`.

- [ ] **Step 1: Write failing tests** — migrations: no table → fail; all ran → ok; one pending fixture migration (`tests/Fixtures/migrations`) → fail with count 1. Debug: debug true + production → fail; debug true + local → ok; custom forbidden list `staging,production` with env staging → fail.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add migrations and debug-mode checks"` (if authorized).

---

### Task 11: Frontend markers

**Files:**
- Create: `src/Http/Middleware/InjectServiceMarkers.php`
- Modify: provider (`pushMiddlewareToGroup('web', InjectServiceMarkers::class)` when enabled; Blade directives `lheHeaderMarker`, `lheFooterMarker` echoing the configured markers)
- Test: `tests/Feature/MarkersTest.php`

**Behaviour:** inject only when: markers enabled, response is `Illuminate\Http\Response`, status 200–299 except 204, `Content-Type` starts with `text/html`, no `Content-Encoding` header. Header marker inserted right after the first `/<head\b[^>]*>/i` match; footer marker right before the **last** case-insensitive `</body>`. A marker already present in the content is not added again; a missing anchor skips that marker only. `Content-Length` set to the new `strlen` when it was present.

**Security:** marker HTML only from config; no request data in markers; markers don't apply to `/lhe/v1` routes (not in `web` group).

- [ ] **Step 1: Write failing tests** (test routes in `web` group returning HTML strings)

```php
it('injects both markers', fn () => $this->get('/page')->assertSee('<head><template id="lhe-service-up-header">UP</template>', false)
    ->assertSee('<template id="lhe-service-up-footer">UP</template></body>', false));
it('handles uppercase and attributes', /* '<HEAD lang="en">…</BODY>' */);                 // Review Focus 5
it('uses the last body tag', /* '<script>"</body>"</script></body>' → marker before final */);
it('skips JSON, streamed, gzip, 204, redirects', fn (string $route) => $this->get($route)->assertDontSee('lhe-service-up', false))
    ->with(['/json', '/stream', '/gzip', '/empty', '/redirect']);
it('skips missing anchors independently', /* no <head> → only footer */);
it('does not duplicate when directive used', /* view with @lheHeaderMarker → exactly one occurrence */);
it('respects overrides and disable flag', /* custom header string; enabled=false → none */);
it('updates content length', /* Content-Length equals strlen(content) */);
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: inject frontend service markers"` (if authorized).

---

### Task 12: Artisan commands and stubs

**Files:**
- Create: `src/Console/MakeHealthCheckCommand.php`, `src/Console/ListHealthRoutesCommand.php`, `src/Console/RunHealthChecksCommand.php`, `src/Console/GenerateTokenCommand.php`, `stubs/health-check.stub`, `stubs/health-check.targets.stub`
- Modify: provider (`commands([...])` when running in console)
- Test: `tests/Feature/Console/*Test.php`

**Interfaces:**
- `make:health-check {name} {--targets} {--force}` — extends `GeneratorCommand`; namespace `App\HealthChecks`; appends `Check` if missing; uses `base_path('stubs/lhe/…')` when published, else package stub. Stubs: the spec's developer-facing example shape, heavily commented (naming rules, "return ok/fail or throw", "keep it fast", "no secrets in message/meta", how to verify with `lhe:run`). `--targets` stub extends `TargetedCheck` with `targets()`/`runTarget()`.
- `lhe:routes {--json}` — rows: `GET|HEAD`, full URL (`url()` with `APP_URL`), check name, targets (detailed regardless of HTTP mode, since CLI is trusted), protection (`token`, `ip allowlist`, `none`). Warnings: production + unprotected → `warn` "Health endpoints are PUBLIC…"; token shorter than 32 chars → `warn`. Never prints the token.
- `lhe:run {check?} {target?} {--json}` — bypasses `ResultCache`, uses `HealthRunner` (state tracking applies — a CLI run is a real run), prints a table (check, target, status, duration, message); exit `0` all ok, `1` any fail, `2` unknown check/target.
- `lhe:token {--show-env}` — prints `bin2hex(random_bytes(32))` (64 chars); `--show-env` prints `LHE_TOKEN=<token>`; writes nothing.

**Security:** generator never overwrites without `--force`; `lhe:routes`/`lhe:run` never print the token; `lhe:token` uses CSPRNG only.

- [ ] **Step 1: Write failing tests** — `make:health-check DiskSpace` creates `app/HealthChecks/DiskSpaceCheck.php` containing `class DiskSpaceCheck extends Check`; `--targets` variant extends `TargetedCheck`; existing file not overwritten without `--force`; generated class passes `php -l` and is discovered. `lhe:routes` lists `/lhe/v1`, `/lhe/v1/ping`, `/lhe/v1/database`, custom route; `--json` is valid JSON; output never contains configured token; public-in-production and short-token warnings shown. `lhe:run` exit codes 0/1/2; `lhe:token` output matches `/^[a-f0-9]{64}$/`, two runs differ.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → green.
- [ ] **Step 5: Checkpoint** — `git commit -m "feat: add artisan commands and generator stubs"` (if authorized).

---

### Task 13: Documentation (README, Boost guideline, SECURITY.md)

**Files:**
- Create/replace: `README.md`, `resources/boost/guidelines/core.blade.php`, `SECURITY.md`, `CHANGELOG.md` (empty header for semantic-release)
- Test: `tests/Feature/DocumentationTest.php`

**Requirements:** README follows spec "Documentation" sections 1–16 (incl. 2a and 6a) in that order, every code block complete with namespaces/imports, every example verified against the implementation (endpoint paths, JSON shapes in both minimal and detailed mode, exact log messages, exact check messages, command output). Sample JSON must be copied from real test output, not invented. Boost guideline per spec. `SECURITY.md`: supported versions, private reporting via GitHub Security Advisories, no public issues for vulnerabilities.

- [ ] **Step 1: Write failing test**

```php
it('documents every env var', function () {
    preg_match_all("/env\\('(LHE_[A-Z_]+)'/", file_get_contents(__DIR__.'/../../config/laravel-health-endpoints.php'), $m);
    $readme = file_get_contents(__DIR__.'/../../README.md');
    foreach (array_unique($m[1]) as $var) {
        expect($readme)->toContain("`{$var}`");
    }
});
it('ships the boost guideline', fn () => expect(file_exists(__DIR__.'/../../resources/boost/guidelines/core.blade.php'))->toBeTrue());
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Write the docs.** **Step 4: Run** → green; manually run every `curl` and artisan example against a Testbench `serve` (`vendor/bin/testbench serve`) and fix mismatches.
- [ ] **Step 5: Checkpoint** — `git commit -m "docs: add README, Boost guideline and security policy"` (if authorized).

---

### Task 14: CI, dependency updates, release automation

**Files:**
- Create: `.github/workflows/ci.yml`, `.github/dependabot.yml`, `.github/workflows/release.yml`, `.releaserc`, `package.json`

**Requirements:**
- `ci.yml` on `push` and `pull_request`: PHP 8.5 × Laravel 13 × `prefer-lowest`/`prefer-stable`; Redis service container (`REDIS_HOST=127.0.0.1`); steps `composer validate --strict`, install, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`, `composer audit`. `permissions: contents: read`.
- `release.yml`, `.releaserc`, `package.json` copied from `lexo/laravel-log-monitor` (package name `lexo-laravel-health-endpoints`); release job `needs` a passing test job.
- All actions pinned to full commit SHAs with `# vX.Y.Z` comments (look up current release SHAs).
- `dependabot.yml`: `composer`, `npm`, `github-actions`, weekly.

- [ ] **Step 1: Write the files.**
- [ ] **Step 2: Verify** `composer validate --strict` passes; `actionlint` (if available) reports no errors; every `uses:` line matches `@[0-9a-f]{40}`: `grep -E 'uses: .+@' .github/workflows/*.yml | grep -vE '@[0-9a-f]{40}'` → no output.
- [ ] **Step 3: Full gate** `vendor/bin/pest && vendor/bin/phpstan analyse && vendor/bin/pint --test && composer audit` → all green.
- [ ] **Step 4: Checkpoint** — `git commit -m "ci: add test, analysis and release workflows"` (if authorized).
