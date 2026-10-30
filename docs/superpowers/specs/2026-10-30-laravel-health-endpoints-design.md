# Laravel Health Endpoints — Design

Date: 2026-10-30

## Intent

A Composer package that, once installed in a Laravel app, exposes JSON health-check endpoints and injects frontend "service up" markers into HTML pages. External monitors call the endpoints / scrape the markers. When a check fails the package logs an error (the app's logging stack — e.g. `lexo/laravel-log-monitor` — takes it from there).

The package must be trivially extensible: app developers (and AI agents working in those apps) should be able to add a new check in minutes without reading package internals.

## Goals

- Built-in checks: **database**, **redis**, **cache**, **storage**, **queue**, **scheduler**, **migrations**, **debug-mode** — all enabled by default, each individually disableable/configurable in env; multi-target where it makes sense (DB/Redis connections, cache stores, disks, queue connections).
- Run one check, one connection of a check, or all checks (including custom ones) in a single call.
- Frontend markers injected automatically into every HTML response; markup overridable per app.
- Optional token protection via env.
- First-class extension API: interface, base class, typed result object, generator command, auto-discovery, written guidance for humans and AI agents.

## Non-goals

- No frontend check endpoint (markers are scraped directly by the external monitor).
- No dashboard, history, or built-in notification channels. Failure → log entry + event; alerting is the app's job (e.g. via `lexo/laravel-log-monitor`).
- No "warning" / degraded state. A check is `ok` or `fail` (keeps HTTP status mapping unambiguous). *(Builder's call — may revisit later.)*
- No JSON:API (`JsonApiResource`) format: health results are not resource collections; JSON:API adds noise for monitors. *(Builder's call.)*

## Anti-goals

- Checking connections the app doesn't actually use (Laravel's default `config/database.php` defines sqlite/mysql/mariadb/pgsql/sqlsrv; checking all would always fail).
- Health endpoints that hang for a long time when a backend is down.
- Leaking secrets (passwords, DSNs, full exception traces) in JSON responses.
- Breaking app HTML (markers must never corrupt a page, streamed responses, downloads, or JSON).

## Constraints

- PHP `^8.5`, Laravel `^13.0` (`illuminate/support: ^13.0`). Future majors added by a one-line constraint bump once tested.
- Dev: `orchestra/testbench ^11`, `pestphp/pest ^4`, `pestphp/pest-plugin-laravel ^4`.
- Structure mirrors `lexo/laravel-log-monitor` (see "Package layout").
- Composer package name `lexo/laravel-health-endpoints`, namespace `LEXO\LaravelHealthEndpoints`. *(Builder's call, following the reference package — confirm.)*
- Releases via semantic-release on `master`/`main`, same `.releaserc`, `package.json`, `.github/workflows/release.yml` as the reference.

## Package layout

```
composer.json
config/laravel-health-endpoints.php
resources/boost/guidelines/core.blade.php     # Laravel Boost AI guideline
stubs/health-check.stub
src/
  Providers/LaravelHealthEndpointsServiceProvider.php
  Facades/LaravelHealthEndpoints.php
  HealthCheckRegistry.php                      # holds registered checks, resolves names
  Contracts/HealthCheck.php
  Contracts/HasTargets.php
  Contracts/HasCustomRoute.php
  Checks/Check.php                             # abstract base class
  Checks/DatabaseCheck.php
  Checks/RedisCheck.php
  Checks/CacheCheck.php
  Checks/StorageCheck.php
  Checks/QueueCheck.php
  Checks/SchedulerCheck.php
  Checks/MigrationsCheck.php
  Checks/DebugModeCheck.php
  Events/HealthCheckFailed.php
  Events/HealthCheckRecovered.php
  Jobs/QueueHeartbeatJob.php
  Support/StateTracker.php                     # last known state per check/target, transition detection
  Support/ResultCache.php
  Support/CheckResult.php                      # final readonly
  Support/Status.php                           # enum
  Support/HealthReport.php                     # aggregate of results → array/JSON + HTTP code
  Http/Controllers/HealthController.php
  Http/Middleware/RestrictHealthIps.php
  Http/Middleware/AuthorizeHealthToken.php
  Http/Middleware/InjectServiceMarkers.php
  Console/MakeHealthCheckCommand.php
  Console/ListHealthRoutesCommand.php
  Console/RunHealthChecksCommand.php
  Console/GenerateTokenCommand.php
  Support/MessageSanitizer.php
tests/ (Pest + Testbench: Unit/, Feature/)
README.md, SECURITY.md, CHANGELOG.md, phpunit.xml, phpstan.neon, pint.json, .github/workflows/ci.yml, .github/dependabot.yml, .releaserc, package.json, .github/workflows/release.yml
```

Provider registered via `extra.laravel.providers` (auto-discovery), config merged in `register()`, published under tag `laravel-health-endpoints-config`; stub published under `laravel-health-endpoints-stubs`.

## Routes

All routes registered by the service provider under prefix `lhe/v1`, named `lhe.*`, GET only (Laravel's `Route::get` also answers `HEAD` automatically — tests must cover `HEAD` returning the same status code with an empty body). They are **not** in the app's `web` or `api` groups (no session, cookies, CSRF); middleware stack: `throttle:<config>` + `RestrictHealthIps` + `AuthorizeHealthToken`.

| Route | Purpose |
|---|---|
| `GET /lhe/v1` | Run every registered check (built-in + custom) |
| `GET /lhe/v1/ping` | Liveness: returns `200` instantly, touches no DB/Redis/cache, never cached, not part of `/lhe/v1` |
| `GET /lhe/v1/database` | All configured DB connections |
| `GET /lhe/v1/database/{connection}` | One DB connection |
| `GET /lhe/v1/redis` | All configured Redis connections |
| `GET /lhe/v1/redis/{connection}` | One Redis connection |
| `GET /lhe/v1/{check}` | Any custom check by name |
| `GET /lhe/v1/{check}/{target}` | Custom check target (if the check implements `HasTargets`) |
| custom path | A check implementing `HasCustomRoute` — still under `/lhe/v1`, same middleware |

Rationale: path segments (not query params) give each check its own URL, status code, and log line, and fit every monitoring tool. Package prefix before version (`/lhe/v1`, not `/v1/lhe`) so the version belongs to this package and never collides with the app's own `/v1` or `/api/v1` routes.

The prefix `lhe/v1` and built-in names are fixed (not configurable). Reserved names: `ping`, `database`, `redis`, `cache`, `storage`, `queue`, `scheduler`, `migrations`, `debug-mode`.

`ping` sits behind the same IP/token middleware as everything else *(builder's call)*; response: `{"status":"ok","timestamp":"...","app":{...}}`.

### HTTP status codes

- `200` — every check run in the request passed.
- `503` — at least one failed.
- `401` — token configured and missing/wrong (evaluated before 404).
- `405` — method other than GET/HEAD.
- `403` — IP allowlist configured and client IP not in it.
- `404` — unknown check name or unknown target (target not in the configured list).
- `429` — rate limit hit.

## Response format

Plain JSON, `Content-Type: application/json`, `Cache-Control: no-store`. Same envelope for aggregate and single-check calls.

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T10:15:00+00:00",
  "duration_ms": 7,
  "cached": false,
  "app": { "name": "Acme", "environment": "production", "version": "2.4.1" },
  "checks": {
    "database": {
      "status": "ok",
      "duration_ms": 5,
      "targets": {
        "mysql":     { "status": "ok", "duration_ms": 3, "meta": { "driver": "mariadb", "version": "11.4.2" } },
        "reporting": { "status": "ok", "duration_ms": 2, "meta": { "driver": "pgsql", "version": "17.0" } }
      }
    },
    "redis": {
      "status": "fail",
      "duration_ms": 2,
      "targets": {
        "default": { "status": "fail", "duration_ms": 2, "message": "Connection refused" }
      }
    },
    "disk-space": { "status": "ok", "duration_ms": 1, "message": "41% used", "meta": { "percent_used": 41 } }
  }
}
```

- `app.name` = `config('app.name')`, `app.environment` = `app()->environment()`, `app.version` = `LHE_APP_VERSION` (null if unset). `LHE_SHOW_APP_INFO=false` removes the `app` block.
- `cached: true` when the response came from the result cache (see "Result caching"); `timestamp` is then the time the checks actually ran.
- Checks without targets have no `targets` key; their `message`/`meta` sit on the check itself.
- A check with targets is `fail` if any target fails.
- `message`, `meta`, `app.version` and target names appear only in detailed mode (see Security → Information disclosure); `message` is always sanitized. Full exception goes to the log only.

## Built-in checks

### Database (`database`)

- Targets: `config('laravel-health-endpoints.database.connections')`, from env `LHE_DB_CONNECTIONS` (comma-separated). Empty → the app's default connection (`config('database.default')`).
- Driver auto-detected per connection from `config("database.connections.{name}.driver")` — works for every driver Laravel supports (mysql, mariadb, pgsql, sqlite, sqlsrv) and third-party drivers. Optional override: `LHE_DB_DRIVER` is not needed given per-connection detection; the "override" requirement is satisfied by `LHE_DB_CONNECTIONS` choosing which connections to check. *(Builder's call — user originally asked for a driver env override; per-connection detection makes it redundant.)*
- Probe: `DB::connection($name)->select('select 1')` (driver-agnostic), plus server version via `getServerVersion()` in `meta`. `meta.driver` = detected driver.
- Connection timeout: the check must not hang indefinitely. Builder decides mechanism (e.g. PDO `ATTR_TIMEOUT` on a cloned connection config, purge connection after probe). Default timeout `LHE_TIMEOUT` (seconds, default 3).

### Redis (`redis`)

- Targets: `LHE_REDIS_CONNECTIONS` (comma-separated). Empty → `default`.
- Probe: `Redis::connection($name)->ping()`; `meta` includes client (phpredis/predis) and server version if cheaply available.
- Same timeout principle.

### Cache (`cache`)

- Targets: `LHE_CACHE_STORES` (comma-separated). Empty → app default cache store.
- Probe: put a random value under a namespaced key (`lhe:probe:<random>`) with short TTL, read it back, compare, forget.

### Storage (`storage`)

- Targets: `LHE_STORAGE_DISKS` (comma-separated). Empty → app default filesystem disk.
- Probe: write a small file under `.lhe-health/` with random content, read it back, compare, delete.

### Scheduler (`scheduler`)

- The provider registers an every-minute scheduled callback (via `callAfterResolving(Schedule::class, ...)`) that writes the current timestamp to the heartbeat store.
- Check fails if no heartbeat exists ("No scheduler heartbeat yet — is `schedule:run` / `schedule:work` running?") or it is older than `LHE_SCHEDULER_MAX_AGE` minutes (default 2).

### Queue (`queue`)

- Targets: `LHE_QUEUE_CONNECTIONS` (comma-separated `connection` or `connection:queue`). Empty → app default queue connection, its default queue.
- The scheduler dispatches `QueueHeartbeatJob` every minute to each target; the job writes a timestamp per target to the heartbeat store.
- Check fails if no heartbeat or it is older than `LHE_QUEUE_MAX_AGE` minutes (default 5). Depends on the scheduler running (message should say so when no heartbeat exists).
- Target whose driver is `sync` → `ok` with message "sync driver — no worker required" (no heartbeat needed). `null` driver → same, "null driver".

### Heartbeat store

- `LHE_HEARTBEAT_STORE` — cache store for scheduler/queue heartbeats. Empty → app default cache store. Must be shared by web, scheduler and worker processes (README must say a per-server `file` store breaks multi-server setups).

### Migrations (`migrations`)

- Fails if any migration file in the app's migration paths has not been run on the default connection (message lists the count and first few names). Uses Laravel's `Migrator` + migration repository. Fails with a clear message if the migrations table doesn't exist.

### Debug mode (`debug-mode`)

- Fails if `config('app.debug')` is true while the environment is one of `LHE_DEBUG_FORBIDDEN_ENVIRONMENTS` (comma-separated, default `production`). Otherwise `ok`, `meta.debug` = bool.

### Enabling / disabling

Every built-in check: `LHE_<NAME>_ENABLED` (`LHE_DATABASE_ENABLED`, `LHE_REDIS_ENABLED`, `LHE_CACHE_ENABLED`, `LHE_STORAGE_ENABLED`, `LHE_QUEUE_ENABLED`, `LHE_SCHEDULER_ENABLED`, `LHE_MIGRATIONS_ENABLED`, `LHE_DEBUG_MODE_ENABLED`), all default `true`. Disabled → route `404`, excluded from `/lhe/v1`, and its scheduled heartbeat (if any) not registered. README must tell apps without Redis / scheduler / queue workers which flags to turn off.

Targets requested via URL that aren't in the configured list → `404` (prevents probing arbitrary connections).

## Frontend markers

`InjectServiceMarkers` middleware, pushed onto the `web` middleware group by the provider (`$router->pushMiddlewareToGroup('web', ...)`).

- Defaults:
  - header: `<template id="lhe-service-up-header">UP</template>` — inserted immediately after the opening `<head ...>` tag.
  - footer: `<template id="lhe-service-up-footer">UP</template>` — inserted immediately before the last `</body>`.
- Overridable: `LHE_MARKER_HEADER`, `LHE_MARKER_FOOTER` (full HTML string), or config.
- `LHE_MARKERS_ENABLED=false` disables auto-injection.
- Only touches responses that are: `Illuminate\Http\Response` (not streamed / binary / JSON), `Content-Type` text/html, and contain the anchor tag. If an anchor is missing, that marker is skipped. If the marker string is already present, it is not added again.
- Blade directives `@lheHeaderMarker` and `@lheFooterMarker` for apps that disable auto-injection or have non-standard layouts.

## Token

- `LHE_TOKEN` empty → no restriction.
- Set → required on every `/lhe/v1*` request. Accepted as `?token=...` (as requested) **or** `Authorization: Bearer ...` header. *(Builder's call: header recommended in README because query strings end up in access logs.)*
- Compared with `hash_equals`. Failure → `401` JSON `{"status":"fail","message":"Unauthorized"}`; not logged as a health failure.
- Rate limit: `LHE_RATE_LIMIT` requests/minute per IP, default 60, adjustable in env.

## IP allowlist

- `LHE_ALLOWED_IPS` empty → endpoints publicly reachable (token still applies if set).
- Set (comma-separated) → only listed IPs may access `/lhe/v1*`; others get `403` JSON `{"status":"fail","message":"Forbidden"}`.
- Matching via `Symfony\Component\HttpFoundation\IpUtils::checkIp`, so IPv4, IPv6 and CIDR ranges (`10.0.0.0/8`) work. *(Builder's call: CIDR support.)*
- Client IP is `$request->ip()`; behind a load balancer/proxy the app must configure Laravel's trusted proxies — documented in README.
- IP check runs before the token check. Applies to health endpoints only, not to frontend markers.

## Security (top priority — applies to every part of the package)

Security is a primary design constraint, not a feature. Every task in the implementation plan must state its security considerations, and every item below needs a test.

### Access control
- Middleware order: rate limit → IP allowlist → token → routing/404. Auth is evaluated **before** check/target resolution, so unauthenticated callers can't enumerate checks or targets (unknown check with bad token → `401`, not `404`).
- Token: `hash_equals`, never logged, never echoed, never part of cache keys or response bodies. Header (`Authorization: Bearer`) recommended; query `token` supported. The package redacts `token` from any URL it logs.
- `php artisan lhe:token` generates a cryptographically random 64-char token (`Str::random` / `random_bytes`) and prints it (`--show-env` prints the `.env` line); it never writes files itself.
- IP allowlist uses `$request->ip()` only (never reads `X-Forwarded-For` directly); trusted proxies are the app's responsibility, documented.
- `ping` and every other route are behind the same protection. Only `GET`/`HEAD` accepted; others → `405`.
- Named rate limiter `lhe` keyed by client IP; applies before auth to blunt token brute force.

### Information disclosure
- `LHE_DETAILS` = `auto` (default) | `always` | `never`:
  - `auto`: detailed output (messages, `meta` incl. server versions, `app.version`, target names) only when the endpoints are protected (token or IP allowlist configured); otherwise public responses contain only `status`, `timestamp`, check names and per-check/per-target `status`.
  - `never`: always minimal. `always`: always detailed (explicit opt-in, README warns).
- Even in detailed mode, `message` is sanitized: no stack traces, file paths, DSNs, hostnames-with-credentials, or passwords. Built-in checks map known exception types to safe messages (e.g. "Connection refused", "Authentication failed", "Timeout"); raw exception goes to the log only. Custom checks' exception messages pass through the same sanitizer (strip `user:pass@`, `password=...`, file paths).
- Package routes never render Laravel's debug error page, even with `APP_DEBUG=true`: the controller catches all `Throwable`s and returns the package's JSON format.
- Response headers: `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex, nofollow`. No CORS headers.
- Routes are outside `web`/`api` groups: no session, no cookies, no CSRF exposure.

### Input handling
- Check names and targets are only ever matched against the registered/configured whitelist — never used to build queries, cache keys from raw input, file paths, or class names. Route constraints: `[a-z0-9-]+` for checks, `[A-Za-z0-9_.:-]+` for targets.
- No request data is ever written into markers, logs (beyond method/path/IP), or responses.

### Side effects of probes
- Cache probe: random key in `lhe:probe:` namespace, short TTL, forgotten in `finally`.
- Storage probe: fixed directory `.lhe-health/`, random filename, no user input, deleted in `finally`; never on a disk not explicitly configured or default.
- Heartbeat job: no payload beyond target name; idempotent; `tries = 1`.
- All probes are read-only or self-cleaning; DB probe is `select 1` only.

### Misconfiguration warnings
- In production with neither token nor IP allowlist: `lhe:routes` prints a prominent warning; a single `warning` log per deploy (cached flag) noting the endpoints are public with minimal details.
- Token shorter than 32 chars → warning in `lhe:routes`.

### Markers
- Marker HTML comes only from config/env (trusted). Response `Content-Length` recalculated after injection.

### Supply chain & process
- Runtime dependencies limited to `illuminate/*` (+ Symfony components already shipped with Laravel).
- CI (GitHub Actions) on every PR: Pest, Larastan/PHPStan at max level, `composer audit`, Pint. Dependabot for Composer, npm and GitHub Actions; actions pinned to commit SHAs.
- `SECURITY.md` with private vulnerability reporting (GitHub security advisories).
- README "Security" section covers all of the above plus a hardening checklist.

## Logging

Log on **state transitions only**, so a monitor polling every minute during an outage produces one error, not one per request.

- State is tracked per check+target by `StateTracker` in the package cache store (see "Result caching"). Unknown previous state counts as `ok`.
- `ok → fail`: `Log::channel($channel)->error('[LHE] {check}[:{target}] check failed: {message}', $context)` and dispatch `HealthCheckFailed`.
- `fail → ok`: `Log::channel($channel)->info('[LHE] {check}[:{target}] check recovered', $context)` with `llm` context so `lexo/laravel-log-monitor` forwards it (it only forwards `error` by default; `alert => true` opts other levels in), and dispatch `HealthCheckRecovered`.
- `fail → fail` / `ok → ok`: nothing logged. *(Builder's call: optionally re-log a still-failing check every `LHE_LOG_REMIND_MINUTES`, default 0 = never.)*
- `$context` = check, target, message, meta, duration_ms, plus `exception` for failures, plus `llm` block:
  ```php
  'llm' => array_filter([
      'alert' => true,                                   // recovery info logs only
      'channel' => config('laravel-health-endpoints.log.llm_channel'),   // LHE_LOG_LLM_CHANNEL, e.g. "monitoring"; null = log-monitor default
      'priority' => config('laravel-health-endpoints.log.llm_priority'), // LHE_LOG_LLM_PRIORITY: important|urgent|null (failures only)
  ])
  ```
  The `llm` key is harmless when log-monitor isn't installed.
- Channel: `LHE_LOG_CHANNEL`, empty → app default.

## Events

`LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed` and `HealthCheckRecovered`, dispatched on the same transitions as logging. Readonly public properties: `string $check`, `?string $target`, `CheckResult $result`, `float $durationMs`. Apps listen to them for custom alerting.

## Result caching

- `LHE_CACHE_TTL` seconds (default 10; `0` disables). Results of `/lhe/v1` and each `/lhe/v1/{check}[/{target}]` cached per URL path; repeated requests within TTL return the cached report with `cached: true` and the same HTTP status.
- Store: `LHE_CACHE_STORE`, default `file` — deliberately not Redis/DB, since those may be what's down. If the store itself throws, run checks uncached (never fail the endpoint because of the cache).
- Same store holds `StateTracker` state. State transitions are only evaluated on real (uncached) runs.
- `ping` and `lhe:run` (CLI) bypass the cache. *(`lhe:run --cached` not needed.)*

## Extension API (custom checks)

Primary design goal: a developer or AI agent can add a check by copying one example.

### Types

```php
namespace LEXO\LaravelHealthEndpoints\Support;

enum Status: string { case Ok = 'ok'; case Fail = 'fail'; }

final readonly class CheckResult
{
    public function __construct(
        public Status $status,
        public ?string $message = null,
        /** @var array<string, scalar|null|array> */
        public array $meta = [],
    ) {}

    public static function ok(?string $message = null, array $meta = []): self;
    public static function fail(string $message, array $meta = []): self;
}
```

`duration_ms` is measured by the framework, not by the check.

### Contracts

```php
interface HealthCheck
{
    /** URL-safe unique name, [a-z0-9-]+, e.g. "disk-space". */
    public function name(): string;

    /** Perform the check. Throwing = fail (message taken from the exception). */
    public function run(): CheckResult;
}

interface HasTargets   // e.g. multiple connections, disks, queues
{
    /** @return list<string> configured targets */
    public function targets(): array;

    public function runTarget(string $target): CheckResult;
}

interface HasCustomRoute
{
    /** Path relative to /lhe/v1, e.g. "billing/stripe". */
    public function path(): string;
}
```

A class implementing `HasTargets` need not implement `run()` meaningfully — the base class provides it (runs all targets). *(Builder may restructure the exact interface split as long as the developer-facing example below stays this simple.)*

### Base class

`abstract class Check implements HealthCheck`:
- `name()` default: class basename, minus `Check` suffix, kebab-cased (`DiskSpaceCheck` → `disk-space`); override via `protected string $name`.
- Execution wrapping (timing, `Throwable` → sanitized fail, state tracking/logging) is done by the package's runner, so checks only return a result or throw.
- Resolved from the container → constructor injection works.

### Developer-facing example (must appear in README, stub, and Boost guideline)

```php
namespace App\HealthChecks;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

class DiskSpaceCheck extends Check
{
    public function run(): CheckResult
    {
        $percent = (int) round(100 - disk_free_space('/') / disk_total_space('/') * 100);

        return $percent < 90
            ? CheckResult::ok("{$percent}% used", ['percent_used' => $percent])
            : CheckResult::fail("Disk almost full: {$percent}% used", ['percent_used' => $percent]);
    }
}
```

→ available at `GET /lhe/v1/disk-space` and included in `GET /lhe/v1`.

### Registration

1. **Auto-discovery**: every non-abstract class implementing `HealthCheck` in `app/HealthChecks` (path configurable, `LHE_DISCOVER_PATH`; discovery can be disabled). Result should be cacheable / cheap in production. *(Builder decides mechanism.)*
2. **Config**: `'checks' => [App\Foo\BarCheck::class]` for checks living elsewhere.
3. **Programmatic**: `LaravelHealthEndpoints::register(BarCheck::class)` — for other packages, from their service provider.

Validation at boot: name format, uniqueness, reserved names (see Routes) → clear exception message telling the developer what to rename.

### Tooling

- `php artisan make:health-check DiskSpace [--targets]` → generates `app/HealthChecks/DiskSpaceCheck.php` from a heavily commented stub (with `--targets`, a `HasTargets` variant).
- `php artisan lhe:routes` → lists every available health-check endpoint: method, full URL (using `APP_URL`), check name, targets, and whether token / IP allowlist protection is active. `--json` for machine-readable output.
- `php artisan lhe:run {check?} {target?}` → runs checks from the CLI, prints a table, exit code non-zero on failure (useful for agents, CI, and debugging without HTTP).

### Documentation

Documentation is a first-class deliverable, written for **both human and AI developers**: scannable, complete, copy-pasteable, no prior package knowledge assumed.

**README.md** must contain, in this order:

1. **Overview** — what the package does in 3–4 sentences; feature list.
2. **Requirements & installation** — `composer require`, "works out of the box" note.
2a. **Configuration** — two ways explained side by side: (a) env vars only, (b) publishing config (`php artisan vendor:publish --tag=laravel-health-endpoints-config`) and stub (`--tag=laravel-health-endpoints-stubs`) — when each is needed (e.g. registering custom checks in `checks` array, multi-line marker HTML), and that published config still reads the same env vars.
3. **Quick start** — the 3 most common setups as ready `.env` blocks (single DB + Redis; no Redis/no queue workers; locked down with token + IP allowlist behind a proxy).
4. **How it works** — short explanation + small diagram (Mermaid or ASCII) of request flow: rate limit → IP allowlist → token → result cache → checks → state tracker → log/events → JSON.
5. **Endpoints** — table: method, path, description, status codes; `curl` example per endpoint (with and without token, query vs header).
6. **Response format** — full annotated example (aggregate, single check, single target, failure, ping) and a field reference table (field, type, always present?, meaning).
6a. **Monitoring from external systems** — how to consume the endpoints from outside the app:
    - **Expected returns table**: for every scenario (all ok, one check fails, one target fails, cached response, unknown check/target, wrong/missing token, IP not allowed, rate limited, ping, HEAD) → HTTP status, `status` field value, example body, what the monitor should do.
    - **Decision rule** for monitors: "alert on non-2xx" is enough; optionally parse `status` / `checks.*.status` for detail; treat 401/403/429 as monitor misconfiguration, not app outage.
    - **Recipes** with copy-paste config: `curl` (with `--fail`, exit codes, `jq` extraction), shell/cron script, Uptime Kuma (HTTP keyword/JSON query), UptimeRobot / Better Stack / Pingdom style (status code + keyword), Kubernetes liveness (`/lhe/v1/ping`) and readiness (`/lhe/v1`) probes, Docker `HEALTHCHECK`, load balancer health checks (AWS ALB / nginx upstream / HAProxy) — each showing URL, token header, expected status.
    - **Frontend markers from outside**: how to verify both markers exist (`curl -s https://app | grep -q 'id="lhe-service-up-header"' && grep -q ...`), keyword monitor setup, meaning of only header present (page broke mid-render) vs none (app/frontend down).
    - **Polling guidance**: recommended interval vs `LHE_CACHE_TTL` and `LHE_RATE_LIMIT`, timeouts to set on the monitor (> `LHE_TIMEOUT` × number of checks), which endpoint to use for what (ping = alive, per-check = targeted alerts, aggregate = overall).
7. **Environment variables reference** — one complete table of **every** `LHE_*` variable: name, type, default, description, example. Grouped (General, Security, Caching, Logging, App info, each built-in check, Markers, Discovery). Must stay in sync with `config/laravel-health-endpoints.php` (a test asserts every `env('LHE_...')` in the config appears in README).
8. **Built-in checks** — one subsection per check: what it verifies, how (probe), targets, env vars, typical failure messages and what to do, prerequisites (scheduler/worker), when to disable.
9. **Frontend markers** — what gets injected where, overriding, disabling, Blade directives, which responses are skipped, how a monitor should scrape them.
10. **Security** — token (header recommended), IP allowlist + trusted proxies, rate limiting, what is never exposed.
11. **Logging & events** — state-transition logic with an example timeline, exact log messages, `llm` context and `lexo/laravel-log-monitor` integration, event classes + listener example.
12. **Writing a custom check** — step-by-step: generate → implement → verify with `lhe:run` → test; full examples for simple check, `HasTargets` check, `HasCustomRoute` check, constructor injection; naming rules; do/don't list (fast, no secrets, throw or return fail).
13. **Artisan commands** — `make:health-check`, `lhe:routes`, `lhe:run`, with sample output.
14. **Testing your checks** — Pest examples.
15. **Troubleshooting / FAQ** — e.g. queue check failing after install, 403 behind load balancer, markers not appearing, multi-server heartbeat store.
16. **Versioning & changelog** — semantic-release, API version `v1` stability promise.

**For AI agents specifically:**
- `resources/boost/guidelines/core.blade.php` — concise rules (where checks live, the canonical example, naming rules, reserved names, "throw or return fail, never echo", "keep checks fast", "no secrets in message/meta", how to verify with `lhe:run`).
- README sections use stable, descriptive headings and complete code blocks (with namespaces/imports) so an agent can copy without guessing.
- PHPDoc with array shapes and `@throws` on every public type; generator stub heavily commented.

## Config file sketch

```php
return [
    'token' => env('LHE_TOKEN', ''),
    'rate_limit' => env('LHE_RATE_LIMIT', 60),              // requests per minute per IP
    'allowed_ips' => env('LHE_ALLOWED_IPS', ''),            // comma-separated IPs/CIDRs, empty = public
    'details' => env('LHE_DETAILS', 'auto'),               // auto|always|never
    'timeout' => env('LHE_TIMEOUT', 3),
    'cache_ttl' => env('LHE_CACHE_TTL', 10),               // seconds, 0 = off
    'cache_store' => env('LHE_CACHE_STORE', 'file'),
    'heartbeat_store' => env('LHE_HEARTBEAT_STORE'),       // null = app default cache store

    'app_info' => [
        'enabled' => env('LHE_SHOW_APP_INFO', true),
        'version' => env('LHE_APP_VERSION'),
    ],

    'log' => [
        'channel' => env('LHE_LOG_CHANNEL'),
        'llm_channel' => env('LHE_LOG_LLM_CHANNEL'),
        'llm_priority' => env('LHE_LOG_LLM_PRIORITY'),
        'remind_minutes' => env('LHE_LOG_REMIND_MINUTES', 0),
    ],

    'database' => [
        'enabled' => env('LHE_DATABASE_ENABLED', true),
        'connections' => env('LHE_DB_CONNECTIONS', ''), // comma-separated, empty = default
    ],
    'redis' => [
        'enabled' => env('LHE_REDIS_ENABLED', true),
        'connections' => env('LHE_REDIS_CONNECTIONS', ''), // empty = "default"
    ],
    'cache' => [
        'enabled' => env('LHE_CACHE_ENABLED', true),
        'stores' => env('LHE_CACHE_STORES', ''),           // empty = default store
    ],
    'storage' => [
        'enabled' => env('LHE_STORAGE_ENABLED', true),
        'disks' => env('LHE_STORAGE_DISKS', ''),           // empty = default disk
    ],
    'queue' => [
        'enabled' => env('LHE_QUEUE_ENABLED', true),
        'connections' => env('LHE_QUEUE_CONNECTIONS', ''), // "conn" or "conn:queue", empty = default
        'max_age' => env('LHE_QUEUE_MAX_AGE', 5),          // minutes
    ],
    'scheduler' => [
        'enabled' => env('LHE_SCHEDULER_ENABLED', true),
        'max_age' => env('LHE_SCHEDULER_MAX_AGE', 2),      // minutes
    ],
    'migrations' => [
        'enabled' => env('LHE_MIGRATIONS_ENABLED', true),
    ],
    'debug_mode' => [
        'enabled' => env('LHE_DEBUG_MODE_ENABLED', true),
        'forbidden_environments' => env('LHE_DEBUG_FORBIDDEN_ENVIRONMENTS', 'production'),
    ],

    'markers' => [
        'enabled' => env('LHE_MARKERS_ENABLED', true),
        'header' => env('LHE_MARKER_HEADER', '<template id="lhe-service-up-header">UP</template>'),
        'footer' => env('LHE_MARKER_FOOTER', '<template id="lhe-service-up-footer">UP</template>'),
    ],

    'discover' => [
        'enabled' => env('LHE_DISCOVER_ENABLED', true),
        'path' => env('LHE_DISCOVER_PATH', 'app/HealthChecks'),
        'namespace' => env('LHE_DISCOVER_NAMESPACE', 'App\\HealthChecks'),
    ],

    'checks' => [
        // App\Support\Health\BarCheck::class,
    ],
];
```

## Testing expectations

Pest + Testbench. Feature tests for: every route and status code, token (query + header, empty token), IP allowlist (empty, allowed, denied, CIDR, IPv6), auth-before-404 (no enumeration), 405 on other methods, `LHE_DETAILS` modes, message sanitizer (credentials/DSN/paths stripped), no debug page with `APP_DEBUG=true`, security headers, token never in logs/cache keys, probe cleanup on failure, production-public warning, `lhe:token`, multi-connection DB (two sqlite connections), single target, unknown target 404, Redis failure path (unreachable host), each new built-in check (pass + fail, sync/null queue drivers, missing/stale heartbeats), `ping`, `HEAD` requests, result caching (hit, TTL expiry, disabled, broken store fallback), state-transition logging (single error on repeated failures, info + `llm` on recovery), events dispatched on transitions only, app info block, custom check discovery/config/facade registration, reserved/duplicate name exceptions, custom route, marker injection (normal HTML, missing anchors, JSON, streamed, already-present, disabled), failure logging, `make:health-check` output, `lhe:routes` output, `lhe:run` exit codes.

## Left to the builder

- Exact timeout mechanism per driver.
- Discovery caching mechanism.
- Internal class split (registry/report/controller) as long as the public API above holds.
- Whether checks in `/lhe/v1` run sequentially (default) — no parallelism required.

## Deferred (discussed, not in this version)

- Non-critical checks (fail reported but HTTP 200).
- Hooking Laravel's `/up` via `DiagnosingHealth`.

## Calls made by the designer (not explicitly decided by the user)

- Package/vendor name `lexo/laravel-health-endpoints`, namespace `LEXO\LaravelHealthEndpoints`.
- Default checked connections: app default DB connection, Redis `default`.
- No separate DB driver env override (auto-detected per connection).
- Bearer header accepted in addition to `?token=`.
- Rate limiting (60/min default), 3s timeout default.
- CIDR/IPv6 support in IP allowlist; IP check before token check.
- No warning state; plain JSON (not JSON:API).
- Built-in checks individually disableable.
- Blade directives as marker fallback.
- `ping` behind IP/token middleware.
- Result cache default TTL 10s on `file` store; state tracking in same store.
- Heartbeat store defaults to app cache store; scheduler max age 2 min, queue 5 min.
- `sync`/`null` queue drivers report ok.
- Debug-mode check forbidden envs default `production`.
- Optional repeat log for still-failing checks (off by default).
- `LHE_DETAILS=auto`: unprotected endpoints return minimal output.
- Minimal mode shows only per-check status (no targets); production-public warning logged once per 24h; internal package errors → `503` JSON; `LHE_DISCOVER_NAMESPACE` for discovery.
- `lhe:token` command, auth before 404, security headers, CI with PHPStan/audit, SECURITY.md.
