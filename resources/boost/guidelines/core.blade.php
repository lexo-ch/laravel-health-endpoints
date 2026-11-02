@verbatim
## Laravel Health Endpoints (lexo/laravel-health-endpoints)

- Exposes JSON health checks under `/lhe/v1` (`200` = all ok, `503` = a check failed) and injects frontend markers into HTML pages of the `web` group. Full docs: `vendor/lexo/laravel-health-endpoints/README.md`.
- Built-in checks: `database`, `redis`, `cache`, `storage`, `queue`, `scheduler`, `migrations`, `debug-mode`, all enabled by default. Turn off unused ones in `.env` (`LHE_REDIS_ENABLED=false`, `LHE_SCHEDULER_ENABLED=false`, `LHE_QUEUE_ENABLED=false`, ...). Configure only via `LHE_*` env vars; never call `env()` outside config files.

### Adding a custom check

- Generate it with `php artisan make:health-check DiskSpace` (StudlyCase only, no subdirectories; `--targets` for a multi-target check). This creates `app/HealthChecks/DiskSpaceCheck.php`.
- Classes in `app/HealthChecks` (namespace `App\HealthChecks`) are auto-discovered. No registration is needed. For classes elsewhere, use the `checks` array of the published config or `LaravelHealthEndpoints::register(Foo::class)` in a service provider's `boot()`.
- Canonical example. Copy it and change the body of `run()`:

<code-snippet name="Custom health check" lang="php">
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
</code-snippet>

- The check above is available at `GET /lhe/v1/disk-space` and included in `GET /lhe/v1`.
- Multiple targets: extend `LEXO\LaravelHealthEndpoints\Checks\TargetedCheck` and implement `targets(): array` (list of names, characters `A-Za-z0-9_.:-`, max 128) and `runTarget(string $target): CheckResult`. Each target gets `GET /lhe/v1/{name}/{target}`.
- Own URL: implement `LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute::path()` returning e.g. `billing/stripe` (relative to `/lhe/v1`; the first segment must not be a check name or reserved name).
- Constructor dependencies are injected by the container.

### Naming rules

- The name is derived from the class: `DiskSpaceCheck` becomes `disk-space`. Override it with `protected ?string $name = 'my-name';` (nullable type is required).
- Names must match `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` and be unique.
- Reserved names (never use them for custom checks): ping, database, redis, cache, storage, queue, scheduler, migrations, debug-mode.

### Rules for check code

- Return `CheckResult::ok(?string $message = null, array $meta = [])` or `CheckResult::fail(string $message, array $meta = [])`, or throw. Any exception becomes a failed check with a sanitized message.
- Never `echo`, `dd()`, `dump()` or `exit` in a check.
- Keep checks fast: finish well within `LHE_TIMEOUT` (default 3 seconds). Always set explicit timeouts on HTTP, socket and API calls. No long-running work.
- Never put secrets (passwords, tokens, API keys, DSNs, personal data) into `message`, `meta` or target names. They can appear in responses and logs.
- Probes must be read-only or clean up after themselves. No side effects such as sending mail, charging cards or writing business data.
- Do not measure duration yourself; the package does it.

### Verifying

- `php artisan lhe:run disk-space`: runs the check from the CLI and prints a table. Exit code `0` = ok, `1` = failed, `2` = unknown check or target. Add `--json` for the full (advanced) JSON report.
- `php artisan lhe:routes`: lists every endpoint, check, target and the active protection, and prints configuration errors.
- In Pest feature tests, call `$this->getJson('/lhe/v1/disk-space')->assertOk()->assertJsonPath('checks.disk-space.status', 'ok');`. Set `LHE_CACHE_TTL=0` and `LHE_CACHE_STORE=array` in `phpunit.xml`. Responses default to statuses only; to assert `message` or `meta` over HTTP, set `config(['laravel-health-endpoints.output' => 'advanced'])` in the test.

### Security

- Protect production with `LHE_TOKEN` (generate with `php artisan lhe:token`; send as `Authorization: Bearer <token>`) and/or `LHE_ALLOWED_IPS`. Protection controls who may call the endpoints, not what they return.
- `LHE_OUTPUT` controls the content: `basic` (default) returns statuses only, `advanced` adds messages, meta and timing. Only use `advanced` behind a token or allowlist. With `LHE_TOKEN` set, a request can ask for advanced output via the `X-LHE-Output: advanced` header.
- Load balancers and liveness probes must use `/lhe/v1/ping`, never `/lhe/v1`: the aggregate fails when any check fails (e.g. pending migrations during a deploy).
- Behind a proxy, configure Laravel's trusted proxies; the allowlist uses `$request->ip()`.
- Frontend markers: if auto-injection is off or the layout is unusual, add the Blade directives `@lheHeaderMarker` (inside `<head>`) and `@lheFooterMarker` (before `</body>`).
@endverbatim
