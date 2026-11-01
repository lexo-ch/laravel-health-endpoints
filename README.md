# Laravel Health Endpoints

Secure JSON health-check endpoints and frontend "service up" markers for Laravel applications.

`lexo/laravel-health-endpoints` · PHP `^8.5` · Laravel `^13.0`

## Table of contents

1. [Overview](#1-overview)
2. [Requirements and installation](#2-requirements-and-installation)
   - [2a. Configuration: env vars or published config](#2a-configuration-env-vars-or-published-config)
3. [Quick start](#3-quick-start)
4. [How it works](#4-how-it-works)
5. [Endpoints](#5-endpoints)
6. [Response format](#6-response-format)
   - [6a. Monitoring from external systems](#6a-monitoring-from-external-systems)
7. [Environment variables reference](#7-environment-variables-reference)
8. [Built-in checks](#8-built-in-checks)
9. [Frontend markers](#9-frontend-markers)
10. [Security](#10-security)
11. [Logging and events](#11-logging-and-events)
12. [Writing a custom check](#12-writing-a-custom-check)
13. [Artisan commands](#13-artisan-commands)
14. [Testing your checks](#14-testing-your-checks)
15. [Troubleshooting / FAQ](#15-troubleshooting--faq)
16. [Versioning and changelog](#16-versioning-and-changelog)

---

## 1. Overview

Install the package and your Laravel app exposes health-check endpoints under `/lhe/v1`. External monitors (Uptime Kuma, UptimeRobot, Better Stack, Kubernetes, load balancers, cron scripts) call them. Every response is JSON with HTTP `200` (all checks passed) or `503` (at least one failed). The package also injects two invisible HTML markers into every page, so a monitor can confirm that the frontend renders completely. A check that starts failing writes one `error` log entry and dispatches an event. Your logging stack (for example [`lexo/laravel-log-monitor`](https://github.com/lexo-ch/laravel-log-monitor)) takes it from there.

**Features**

- Eight built-in checks, all on by default: `database`, `redis`, `cache`, `storage`, `queue`, `scheduler`, `migrations`, `debug-mode`. Each can be turned off and configured from `.env`.
- Multi-target checks: probe every configured DB/Redis connection, cache store, disk and queue at once, or one of them (`/lhe/v1/database/reporting`).
- One call for everything (`/lhe/v1`), one call per check, one call per target, and a liveness endpoint (`/lhe/v1/ping`).
- Custom checks in minutes: extend one base class, drop the file in `app/HealthChecks`, and it is auto-discovered. A generator command is included.
- Secure by default: optional token (Bearer header or `?token=`), IP/CIDR allowlist, per-IP rate limiting, identical output with or without protection, sanitized messages, no debug pages, no sessions/cookies/CSRF.
- Logs on state transitions only. A one-hour outage produces one error and one recovery entry, not 60 errors.
- `HealthCheckFailed` / `HealthCheckRecovered` events for your own alerting.
- Short result cache (10 s by default) so aggressive polling does not hammer your backends.
- Frontend markers (`<template id="lhe-service-up-header">UP</template>` and `...-footer`), with Blade directives as a fallback.
- Artisan tooling: `make:health-check`, `lhe:routes`, `lhe:run`, `lhe:token`.

---

## 2. Requirements and installation

| Requirement | Version |
|---|---|
| PHP | `^8.5` |
| Laravel | `^13.0` |

```bash
composer require lexo/laravel-health-endpoints
```

The service provider is auto-discovered. Nothing else is required, and the package works out of the box:

- `GET /lhe/v1` runs every enabled check.
- Pages rendered through the `web` middleware group get the two frontend markers.
- Without `LHE_TOKEN` or `LHE_ALLOWED_IPS`, the endpoints are public. The response is the same either way (messages, versions, connection names); protection only controls **who** may call them.

> **Turn off what your app does not use.** All eight built-in checks are enabled by default. If your app has no Redis, no cron running `schedule:run`, or no queue worker, the matching check fails, and so does `/lhe/v1`. Set the matching flags right after installing:
>
> ```dotenv
> LHE_REDIS_ENABLED=false       # app does not use Redis
> LHE_SCHEDULER_ENABLED=false   # no cron / schedule:work running
> LHE_QUEUE_ENABLED=false       # no queue worker (the queue heartbeat also needs the scheduler)
> ```
>
> See [Quick start](#3-quick-start) for ready-made `.env` blocks.

Check what is exposed:

```bash
php artisan lhe:routes   # every endpoint URL, check, target and the active protection
php artisan lhe:run      # run all checks from the CLI and print a table
```

In production, cache your routes and config (see [Troubleshooting](#performance-without-route-cache)):

```bash
php artisan config:cache
php artisan route:cache
```

### 2a. Configuration: env vars or published config

There are two ways to configure the package. Both use the **same** environment variables.

| | (a) Env vars only (recommended) | (b) Published config file |
|---|---|---|
| How | Add `LHE_*` variables to `.env` | `php artisan vendor:publish --tag=laravel-health-endpoints-config` creates `config/laravel-health-endpoints.php` |
| Good for | Everything that is a plain value: token, IPs, connections, flags, TTLs, single-line markers | Registering custom checks that live **outside** `app/HealthChecks` (the `checks` array); multi-line or computed marker HTML; values you want under version control instead of `.env` |
| Env vars still work? | Yes | Yes. The published file reads the same `env('LHE_...')` calls, so you only change the entries you need |
| After changes in production | `php artisan config:cache` | `php artisan config:cache` |

**(a) Env vars only:**

```dotenv
LHE_TOKEN=4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c
LHE_DB_CONNECTIONS=mysql,reporting
LHE_REDIS_ENABLED=false
```

**(b) Published config.** Example `config/laravel-health-endpoints.php` excerpt with a custom check registered and a multi-line marker:

```php
<?php

declare(strict_types=1);

return [
    // ... all other keys as published; they keep reading env('LHE_...') ...

    'markers' => [
        'enabled' => env('LHE_MARKERS_ENABLED', true),
        'header' => env('LHE_MARKER_HEADER', '<template id="lhe-service-up-header">UP</template>'),
        'footer' => <<<'HTML'
            <template id="lhe-service-up-footer">UP</template>
            <meta name="x-acme-render" content="complete">
            HTML,
    ],

    'checks' => [
        App\Support\Health\BillingCheck::class,
    ],
];
```

> **List values: comma-separated string or PHP array.** `allowed_ips`, `database.connections`, `redis.connections`, `cache.stores`, `storage.disks`, `queue.connections` and `debug_mode.forbidden_environments` accept either a comma-separated string (`'allowed_ips' => '10.0.0.0/8,192.168.1.10'`, the form the env vars use) or a PHP array of strings (`'allowed_ips' => ['10.0.0.0/8', '192.168.1.10']`). Entries are trimmed, empty and non-string entries are ignored. **`token` must be a string.** Any other non-empty value (array, int, object) is treated as a misconfiguration and fails closed: every `/lhe/v1*` request gets `401` and `php artisan lhe:routes` warns about it.

**Publishing the generator stubs** (optional) lets you change the template that `php artisan make:health-check` uses, for example to add your team's header comment or base class:

```bash
php artisan vendor:publish --tag=laravel-health-endpoints-stubs
```

This copies the stubs to `stubs/lhe/health-check.stub` (plain check) and `stubs/lhe/health-check.targets.stub` (`--targets` variant). When these files exist, `make:health-check` uses them instead of the package's own. Placeholders: `{{ namespace }}`, `{{ class }}`, `{{ name }}` (the derived check name, e.g. `disk-space`).

---

## 3. Quick start

Pick the block closest to your setup and paste it into `.env`. Every variable is explained in the [reference](#7-environment-variables-reference).

**Setup 1: single database + Redis, scheduler and queue worker running (typical production app)**

```dotenv
LHE_TOKEN=4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c   # php artisan lhe:token
# Shared store for scheduler/queue heartbeats (must be reachable from web, scheduler and worker processes)
LHE_HEARTBEAT_STORE=redis
```

**Setup 2: no Redis, no queue workers (small app or shared hosting, cron runs the scheduler)**

```dotenv
LHE_TOKEN=4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c
LHE_REDIS_ENABLED=false
LHE_QUEUE_ENABLED=false
# No cron either? Then also:
# LHE_SCHEDULER_ENABLED=false
```

**Setup 3: locked down, token + IP allowlist, behind a load balancer / reverse proxy**

```dotenv
LHE_TOKEN=4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c
# Your monitoring server(s) and the internal network (IPv4, IPv6 and CIDR supported)
LHE_ALLOWED_IPS=203.0.113.10,2001:db8::/32,10.0.0.0/8
LHE_RATE_LIMIT=30
LHE_DB_CONNECTIONS=mysql,reporting
LHE_HEARTBEAT_STORE=redis
```

Behind a proxy, Laravel must trust it. Otherwise every request appears to come from the proxy's IP and the allowlist answers `403`. In `bootstrap/app.php`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // IPs/CIDRs of your load balancer or reverse proxy
        $middleware->trustProxies(at: ['10.0.0.0/8']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

Then verify:

```bash
php artisan lhe:routes
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1
```

---

## 4. How it works

```
 Monitor ──GET /lhe/v1[/{check}[/{target}]]──►  Laravel app
                                                   │
   ┌───────────────────────────────────────────────┘
   ▼
 1. Rate limit (per IP, LHE_RATE_LIMIT/min) ──── exceeded ──► 429
   ▼
 2. IP allowlist (LHE_ALLOWED_IPS, if set) ───── not listed ─► 403
   ▼
 3. Token (LHE_TOKEN, if set) ─────────────────── missing/wrong ► 401
   ▼
 4. Routing: known check / configured target? ── no ─────────► 404
   ▼
 5. Result cache (LHE_CACHE_TTL s, per URL) ──── hit ──► cached report ("cached": true)
   ▼ miss
 6. Run checks sequentially (each one timed; exceptions become sanitized "fail")
   ▼
 7. State tracker (per check/target): ok→fail / fail→ok?
   │        └── transition ──► Log error/info + HealthCheckFailed / HealthCheckRecovered event
   ▼
 8. JSON response: 200 (all ok) or 503 (any fail)
```

- **Auth runs before routing.** Without a valid token, an unknown check returns `401`, not `404`, so callers cannot enumerate checks or targets.
- **Targets come only from configuration.** A target in the URL that is not in the configured list returns `404`. The package never connects to a connection, disk or store you did not configure.
- **Liveness (`/lhe/v1/ping`)** goes through steps 1 to 3 and then answers immediately. It never touches the database, Redis or the app's cache stores (the rate-limit counter lives in `LHE_CACHE_STORE`, `file` by default), and is never cached.
- Routes live outside the `web` and `api` groups: no session, no cookies, no CSRF.
- The scheduler and queue checks read **heartbeats**. The package registers an every-minute scheduled callback (`lhe:scheduler-heartbeat`) and an every-minute queued job per queue target (`lhe:queue-heartbeat:{connection}:{queue}`; `redis` and `redis:default` count as one target). Both write a timestamp into the heartbeat store. The job is `ShouldBeUnique` per target with `uniqueFor = 60` (lock in the heartbeat store): a new heartbeat job is not queued while the previous one for that target is still waiting and less than 60 s old, so overlapping scheduler runs (several servers running `schedule:run`) cannot queue duplicates. It does not stop heartbeat jobs from accumulating while no worker runs (about one per minute); they are tiny and are worked off quickly once a worker starts. A longer lock would let a lost job delay the next heartbeat past `LHE_QUEUE_MAX_AGE` and cause a false failure.

---

## 5. Endpoints

All endpoints accept `GET` and `HEAD` only. Every response is `Content-Type: application/json` with the headers `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff` and `X-Robots-Tag: noindex, nofollow`.

| Method | Path | Description | Status codes |
|---|---|---|---|
| GET, HEAD | `/lhe/v1` | Run every enabled check (built-in + custom) | 200, 503, 401, 403, 429 |
| GET, HEAD | `/lhe/v1/ping` | Liveness: answers instantly, touches nothing, never cached, not part of `/lhe/v1` | 200, 401, 403, 429 |
| GET, HEAD | `/lhe/v1/database` | All configured DB connections | 200, 503, 401, 403, 404 (disabled), 429 |
| GET, HEAD | `/lhe/v1/database/{connection}` | One DB connection | 200, 503, 401, 403, 404, 429 |
| GET, HEAD | `/lhe/v1/redis` · `/lhe/v1/redis/{connection}` | All / one Redis connection | same as above |
| GET, HEAD | `/lhe/v1/cache` · `/lhe/v1/cache/{store}` | All / one cache store | same as above |
| GET, HEAD | `/lhe/v1/storage` · `/lhe/v1/storage/{disk}` | All / one filesystem disk | same as above |
| GET, HEAD | `/lhe/v1/queue` · `/lhe/v1/queue/{connection[:queue]}` | All / one queue target | same as above |
| GET, HEAD | `/lhe/v1/scheduler` | Scheduler heartbeat | 200, 503, 401, 403, 404 (disabled), 429 |
| GET, HEAD | `/lhe/v1/migrations` | Pending migrations | same as above |
| GET, HEAD | `/lhe/v1/debug-mode` | `APP_DEBUG` in a forbidden environment | same as above |
| GET, HEAD | `/lhe/v1/{check}` | Any custom check by name (e.g. `/lhe/v1/disk-space`) | 200, 503, 401, 403, 404, 429 |
| GET, HEAD | `/lhe/v1/{check}/{target}` | One target of a custom check that has targets | 200, 503, 401, 403, 404, 429 |
| GET, HEAD | `/lhe/v1/{custom/path}` | A custom check with its own path (e.g. `/lhe/v1/billing/stripe`) | 200, 503, 401, 403, 429 |
| other | any `/lhe/v1/...` | `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `TRACE`, `CONNECT`, `QUERY`, `PURGE`, `LINK`, `UNLINK` and the WebDAV verbs (`PROPFIND`, `PROPPATCH`, `MKCOL`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`, `SEARCH`, `REPORT`) | 405 (`Allow: GET, HEAD`), after auth. Any other, non-standard method gets Laravel's own `405` without the package's headers |

Status code meaning:

| Code | Meaning |
|---|---|
| `200` | Every check run by this request passed |
| `503` | At least one check (or target) failed. Also returned with `"message":"Health check error"` when the package itself cannot run (e.g. an invalid custom check registration; see [FAQ](#every-endpoint-returns-503-health-check-error)) |
| `401` | `LHE_TOKEN` is set and the token is missing or wrong (checked before `404`) |
| `403` | `LHE_ALLOWED_IPS` is set and the client IP is not in it |
| `404` | Unknown or disabled check, unknown target, or path not matching any route |
| `405` | Method other than `GET`/`HEAD` |
| `429` | More than `LHE_RATE_LIMIT` requests per minute from this IP (`Retry-After` header set) |

Route constraints: check names match `[a-z][a-z0-9-]*`; targets match `[A-Za-z0-9_.:-]{1,128}`. Anything else is a `404`.

### curl examples

```bash
# Token via Authorization header (recommended: query strings end up in access logs)
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1

# Token via query string (for tools that cannot send headers)
curl -i "https://app.example.com/lhe/v1?token=$LHE_TOKEN"

# Without token (only when LHE_TOKEN is empty)
curl -i https://app.example.com/lhe/v1

# Liveness
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/ping

# One check, all its targets
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/database

# One target
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/database/reporting
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/redis/default
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/cache/file
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/storage/local
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/queue/redis
curl -i -H "Authorization: Bearer $LHE_TOKEN" "https://app.example.com/lhe/v1/queue/redis:emails"

# Checks without targets
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/scheduler
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/migrations
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/debug-mode

# Custom checks
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/disk-space
curl -i -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/billing/stripe

# HEAD: same status code, empty body
curl -I -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1
```

If both a non-empty `Authorization: Bearer` header and `?token=` are sent, the header wins.

---

## 6. Response format

Bodies are plain JSON (not JSON:API). The examples below were captured from real requests and pretty-printed with `jq`. On the wire the JSON is compact (`{"status":"ok",...}`, no spaces). Non-ASCII characters and slashes are sent as-is (`—`, `https://…`); only `<`, `>`, `&`, `'` and `"` inside string values are escaped as `\u003C`-style sequences. `checks` and `targets` are always JSON objects (`{}` when empty), even when target names are numeric. Keep that in mind for keyword monitors.

The body is the same with or without `LHE_TOKEN` / `LHE_ALLOWED_IPS`: protection decides who gets a response, never what it contains.

### Aggregate, all ok: `GET /lhe/v1` → `200`

Two DB connections (`LHE_DB_CONNECTIONS=sqlite,reporting`), Redis, a `file` cache store, a `local` disk, a `redis` queue, plus two custom checks (`disk-space` and `stripe-api`):

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T15:24:21+00:00",
  "duration_ms": 6,
  "cached": false,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "database": {
      "status": "ok",
      "duration_ms": 1,
      "targets": {
        "sqlite": {
          "status": "ok",
          "duration_ms": 1,
          "meta": {
            "driver": "sqlite",
            "version": "3.54.1"
          }
        },
        "reporting": {
          "status": "ok",
          "duration_ms": 0,
          "meta": {
            "driver": "sqlite",
            "version": "3.54.1"
          }
        }
      }
    },
    "redis": {
      "status": "ok",
      "duration_ms": 4,
      "targets": {
        "default": {
          "status": "ok",
          "duration_ms": 4,
          "meta": {
            "client": "predis",
            "version": "8.2.3"
          }
        }
      }
    },
    "cache": {
      "status": "ok",
      "duration_ms": 0,
      "targets": {
        "file": {
          "status": "ok",
          "duration_ms": 0
        }
      }
    },
    "storage": {
      "status": "ok",
      "duration_ms": 0,
      "targets": {
        "local": {
          "status": "ok",
          "duration_ms": 0
        }
      }
    },
    "queue": {
      "status": "ok",
      "duration_ms": 0,
      "targets": {
        "redis": {
          "status": "ok",
          "duration_ms": 0,
          "meta": {
            "last_heartbeat": "2026-10-30T15:23:53+00:00"
          }
        }
      }
    },
    "scheduler": {
      "status": "ok",
      "duration_ms": 0,
      "meta": {
        "last_heartbeat": "2026-10-30T15:23:53+00:00"
      }
    },
    "migrations": {
      "status": "ok",
      "duration_ms": 0,
      "meta": {
        "pending": 0
      }
    },
    "debug-mode": {
      "status": "ok",
      "duration_ms": 0,
      "meta": {
        "debug": false
      }
    },
    "disk-space": {
      "status": "ok",
      "duration_ms": 0,
      "message": "58% used",
      "meta": {
        "percent_used": 58
      }
    },
    "stripe-api": {
      "status": "ok",
      "duration_ms": 0,
      "message": "Stripe API reachable"
    }
  }
}
```

### Single check: `GET /lhe/v1/database` → `200`

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T15:24:21+00:00",
  "duration_ms": 0,
  "cached": false,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "database": {
      "status": "ok",
      "duration_ms": 0,
      "targets": {
        "sqlite": {
          "status": "ok",
          "duration_ms": 0,
          "meta": {
            "driver": "sqlite",
            "version": "3.54.1"
          }
        },
        "reporting": {
          "status": "ok",
          "duration_ms": 0,
          "meta": {
            "driver": "sqlite",
            "version": "3.54.1"
          }
        }
      }
    }
  }
}
```

### Single target: `GET /lhe/v1/database/reporting` → `200`

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T15:24:21+00:00",
  "duration_ms": 0,
  "cached": false,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "database": {
      "status": "ok",
      "duration_ms": 0,
      "targets": {
        "reporting": {
          "status": "ok",
          "duration_ms": 0,
          "meta": {
            "driver": "sqlite",
            "version": "3.54.1"
          }
        }
      }
    }
  }
}
```

### Custom check with its own path: `GET /lhe/v1/billing/stripe` → `200`

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T15:24:21+00:00",
  "duration_ms": 0,
  "cached": false,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "stripe-api": {
      "status": "ok",
      "duration_ms": 0,
      "message": "Stripe API reachable"
    }
  }
}
```

A check with a custom path is also reachable under its name (`/lhe/v1/stripe-api`). Both URLs share one result-cache entry.

### Failure: `GET /lhe/v1` → `503`

The `reporting` database is missing, Redis is down, and a custom check named `throwing` threw `RuntimeException('SQLSTATE[HY000] mysql://app:s3cret@db.internal/app password=hunter2 in /var/www/app/Foo.php:12')`. The credentials, password and file path were stripped from the response, and the raw exception went to the log only. (Cache, storage, queue, scheduler and migrations checks were disabled for this capture.)

```json
{
  "status": "fail",
  "timestamp": "2026-10-30T15:28:43+00:00",
  "duration_ms": 37,
  "cached": false,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "database": {
      "status": "fail",
      "duration_ms": 27,
      "targets": {
        "sqlite": {
          "status": "ok",
          "duration_ms": 12,
          "meta": {
            "driver": "sqlite",
            "version": "3.54.1"
          }
        },
        "reporting": {
          "status": "fail",
          "duration_ms": 1,
          "message": "Database not found"
        }
      }
    },
    "redis": {
      "status": "fail",
      "duration_ms": 9,
      "targets": {
        "default": {
          "status": "fail",
          "duration_ms": 9,
          "message": "Connection refused"
        }
      }
    },
    "debug-mode": {
      "status": "ok",
      "duration_ms": 0,
      "meta": {
        "debug": false
      }
    },
    "disk-space": {
      "status": "ok",
      "duration_ms": 0,
      "message": "58% used",
      "meta": {
        "percent_used": 58
      }
    },
    "throwing": {
      "status": "fail",
      "duration_ms": 0,
      "message": "SQLSTATE[HY000] mysql://***@db.internal/app password=*** in [path]"
    }
  }
}
```

### Single target failure: `GET /lhe/v1/database/reporting` → `503`

```json
{
  "status": "fail",
  "timestamp": "2026-10-30T15:28:43+00:00",
  "duration_ms": 0,
  "cached": false,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "database": {
      "status": "fail",
      "duration_ms": 0,
      "targets": {
        "reporting": {
          "status": "fail",
          "duration_ms": 0,
          "message": "Database not found"
        }
      }
    }
  }
}
```

### Cached response

A second request within `LHE_CACHE_TTL` seconds returns the stored report with `"cached": true` and the **same** HTTP status. `timestamp` and `duration_ms` are those of the run that actually executed the checks:

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T15:24:21+00:00",
  "duration_ms": 0,
  "cached": true,
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  },
  "checks": {
    "stripe-api": {
      "status": "ok",
      "duration_ms": 0,
      "message": "Stripe API reachable"
    }
  }
}
```

### Ping: `GET /lhe/v1/ping` → `200`

```json
{
  "status": "ok",
  "timestamp": "2026-10-30T15:24:21+00:00",
  "app": {
    "name": "Acme",
    "environment": "production",
    "version": "2.4.1"
  }
}
```

### Error bodies

Errors use a fixed body. The message is a constant and never contains request data:

| Status | Body |
|---|---|
| `401` | `{"status":"fail","message":"Unauthorized"}` |
| `403` | `{"status":"fail","message":"Forbidden"}` |
| `404` | `{"status":"fail","message":"Not found"}` |
| `405` | `{"status":"fail","message":"Method not allowed"}` |
| `429` | `{"status":"fail","message":"Too many requests"}` |
| `503` (package error) | `{"status":"fail","message":"Health check error"}` |

### Field reference

| Field | Type | Always present? | Meaning |
|---|---|---|---|
| `status` | `"ok"` \| `"fail"` | yes | Overall result. `fail` if any check in this response failed |
| `timestamp` | ISO 8601 string | yes | When the checks actually ran (for cached responses, the original run) |
| `duration_ms` | int | yes | Total run time of all checks in this response |
| `cached` | bool | yes | `true` if served from the result cache |
| `app` | object | unless `LHE_SHOW_APP_INFO=false` | Application info |
| `app.name` | string | with `app` | `config('app.name')` |
| `app.environment` | string | with `app` | `app()->environment()` |
| `app.version` | string \| null | with `app` | `version` key of the app's `composer.json` (read once per process; at most 64 characters of `A-Z a-z 0-9 . + _ -`), else `null` |
| `checks` | object | yes | One entry per check run, keyed by check name |
| `checks.{name}.status` | `"ok"` \| `"fail"` | yes | Check result. A check with targets is `fail` if any target failed |
| `checks.{name}.duration_ms` | int | yes | Check run time (rounded) |
| `checks.{name}.message` | string | only if set | Sanitized message. Checks **without** targets; on checks with targets only when the target list itself failed (e.g. `No targets configured`) |
| `checks.{name}.meta` | object | only if non-empty | Extra data (checks **without** targets) |
| `checks.{name}.targets` | object | checks **with** targets only | One entry per target run, keyed by target name |
| `checks.{name}.targets.{target}.status` | `"ok"` \| `"fail"` | with `targets` | Target result |
| `checks.{name}.targets.{target}.duration_ms` | int | with `targets` | Target run time |
| `checks.{name}.targets.{target}.message` | string | only if set | Sanitized message |
| `checks.{name}.targets.{target}.meta` | object | only if non-empty | Extra data (e.g. `driver`, `version`) |
| `message` | string | error bodies only | Fixed error text (see above) |

Clients should ignore fields they do not know. New optional fields may be added within `v1`.

---

### 6a. Monitoring from external systems

#### Expected returns

| Scenario | HTTP | `status` | Example body (compact, as sent) | What the monitor should do |
|---|---|---|---|---|
| All checks ok | `200` | `ok` | `{"status":"ok","timestamp":"…","checks":{"database":{"status":"ok"},…}}` | Nothing: healthy |
| One check fails | `503` | `fail` | `{"status":"fail",…,"checks":{"redis":{"status":"fail"},"database":{"status":"ok"}}}` | Alert. Optionally read `checks.*.status` to name the failing check |
| One target fails | `503` | `fail` | `…"database":{"status":"fail","targets":{"reporting":{"status":"fail","message":"Database not found"},…}}` | Alert. The body shows which target |
| Cached response | same as the original (`200` or `503`) | same as the original | `{…,"cached":true,…}` | Treat like a fresh response. Results are at most `LHE_CACHE_TTL` seconds old |
| Unknown check or target | `404` | `fail` | `{"status":"fail","message":"Not found"}` | Fix the monitor URL (or the check was disabled or renamed) |
| Missing or wrong token | `401` | `fail` | `{"status":"fail","message":"Unauthorized"}` | **Monitor misconfiguration**, not an outage: fix the token |
| IP not allowed | `403` | `fail` | `{"status":"fail","message":"Forbidden"}` | **Monitor misconfiguration**: allowlist the monitor IP or fix trusted proxies |
| Rate limited | `429` | `fail` | `{"status":"fail","message":"Too many requests"}` + `Retry-After` header | **Monitor misconfiguration**: poll less often or raise `LHE_RATE_LIMIT` |
| Wrong method | `405` | `fail` | `{"status":"fail","message":"Method not allowed"}` + `Allow: GET, HEAD` | Use `GET` or `HEAD` |
| Package error | `503` | `fail` | `{"status":"fail","message":"Health check error"}` | Alert. Check `storage/logs` for `[LHE] Health endpoint error` |
| Ping | `200` | `ok` | `{"status":"ok","timestamp":"…"}` | App process is alive (says nothing about DB/Redis) |
| `HEAD` on any endpoint | same as `GET` | (no body) | empty | Use the status code only |
| No response / timeout / `5xx` other than `503` from a proxy | none / `502` / `504` | (none) | n/a | Alert: app, PHP-FPM or the proxy is down |

#### Decision rule

1. **Alert on any non-2xx response or a timeout.** That is enough for most monitors.
2. Optionally, parse `status` / `checks.*.status` (and `checks.*.targets.*`) to put the failing check name in the alert.
3. Treat `401`, `403` and `429` as **monitor misconfiguration**, not an app outage. Route them to whoever owns the monitor, not to the on-call developer.

#### Recipes

**curl** (`--fail` makes curl exit non-zero on HTTP ≥ 400):

```bash
curl -fsS --max-time 20 -o /dev/null -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1
echo $?   # 0 = 200 ok; 22 = HTTP error (503, 401, 403, 404, 429); 28 = timeout; 6/7 = DNS/connection failure

# Overall status only
curl -sS -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1 | jq -r '.status'
# → fail

# Status per check
curl -sS -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1 | jq -r '.checks | to_entries[] | "\(.key)\t\(.value.status)"'
# → database   ok
#   redis      ok
#   ...
#   scheduler  fail
```

**Shell script for cron / Nagios-style checks** (exit 0 healthy, 1 unhealthy, 2 unreachable, 3 misconfigured):

```bash
#!/usr/bin/env bash
# Exit codes: 0 = healthy, 1 = unhealthy (503), 2 = unreachable/unexpected, 3 = monitor misconfigured
set -uo pipefail

URL="${LHE_URL:-https://app.example.com/lhe/v1}"
TOKEN="${LHE_TOKEN:?set LHE_TOKEN}"

response=$(curl -sS --max-time 20 -H "Authorization: Bearer ${TOKEN}" -w '\n%{http_code}' "$URL") || {
    echo "CRITICAL: ${URL} unreachable"
    exit 2
}
code=${response##*$'\n'}
body=${response%$'\n'*}

case "$code" in
    200)
        echo "OK"
        exit 0
        ;;
    503)
        echo "CRITICAL: failing checks:"
        echo "$body" | jq -r '.checks | to_entries[] | select(.value.status == "fail") | .key as $check
            | if .value.targets then (.value.targets | to_entries[] | select(.value.status == "fail") | "  \($check)/\(.key): \(.value.message // "-")")
              else "  \($check): \(.value.message // "-")" end'
        exit 1
        ;;
    401|403|429)
        echo "UNKNOWN: monitor misconfigured (HTTP ${code})"
        exit 3
        ;;
    *)
        echo "CRITICAL: unexpected HTTP ${code}"
        exit 2
        ;;
esac
```

Sample output:

```
CRITICAL: failing checks:
  redis/default: Connection refused
  scheduler: Last scheduler heartbeat 4 min ago (max 2)
```

Crontab, every minute:

```cron
* * * * * LHE_TOKEN=your-token /usr/local/bin/lhe-check.sh || /usr/local/bin/notify-oncall "app health failed"
```

**Uptime Kuma**

- Monitor type: *HTTP(s) - Json Query*
- URL: `https://app.example.com/lhe/v1`
- Headers (JSON): `{"Authorization": "Bearer <LHE_TOKEN>"}`
- Json Query: `status`, expected value `ok`
- Accepted status codes: `200-299`. A `503` already marks the monitor down.
- Alternative: monitor type *HTTP(s) - Keyword*, keyword `"status":"ok"` (no spaces, as sent on the wire).

**UptimeRobot / Better Stack / Pingdom (status code + keyword)**

- URL: `https://app.example.com/lhe/v1` (or a per-check URL such as `/lhe/v1/database`)
- If the tool supports custom request headers: `Authorization: Bearer <LHE_TOKEN>`. If it does not, append `?token=<LHE_TOKEN>` (the token then appears in your access logs; prefer an IP allowlist for such tools).
- Expected status: `200`
- Keyword (optional): `"status":"ok"` must be present
- Timeout: at least 2 × `LHE_TIMEOUT` × the number of server targets for the aggregate endpoint, typically 20–30 s (see polling guidance below)

**Kubernetes liveness and readiness probes**

```yaml
livenessProbe:
  httpGet:
    path: /lhe/v1/ping
    port: 8080
    httpHeaders:
      - name: Authorization
        value: Bearer 4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c
  periodSeconds: 10
  timeoutSeconds: 3
  failureThreshold: 3
readinessProbe:
  httpGet:
    path: /lhe/v1
    port: 8080
    httpHeaders:
      - name: Authorization
        value: Bearer 4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c
  periodSeconds: 15
  timeoutSeconds: 15
  failureThreshold: 2
```

- Liveness must use `/lhe/v1/ping`. It does not depend on the DB or Redis, so an outage of a backend does not restart your pods.
- For readiness, `/lhe/v1` fails when **any** check fails, including `debug-mode` or `migrations`. If one shared backend going down should not take every pod out of rotation, point readiness at the checks that matter for serving traffic (e.g. `/lhe/v1/database`).
- Probes come from the kubelet on the node. If `LHE_ALLOWED_IPS` is set, include the node/pod network CIDR. Better still, keep the token in a Secret and reference it in the probe value via your templating tool.

**Docker `HEALTHCHECK`**

```dockerfile
HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
  CMD curl -fsS -o /dev/null -H "Authorization: Bearer ${LHE_TOKEN}" http://localhost/lhe/v1/ping || exit 1
```

(`LHE_TOKEN` must be set in the container environment. Use `/lhe/v1` instead of `/lhe/v1/ping` if the container should report unhealthy when backends fail.)

**Load balancer health checks**

AWS ALB target group (ALB health checks cannot send custom headers, so allow the VPC CIDR instead of using a token, or use `?token=`):

```text
Protocol:           HTTP
Path:               /lhe/v1/ping
Success codes:      200
Interval / Timeout: 15 s / 5 s
Healthy / Unhealthy threshold: 2 / 3
```

```dotenv
# ALB health checks come from the load balancer's own IPs inside the VPC
LHE_ALLOWED_IPS=10.0.0.0/16
```

HAProxy (2.2+):

```haproxy
backend app
    option httpchk
    http-check send meth GET uri /lhe/v1/ping hdr Authorization "Bearer 4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c"
    http-check expect status 200
    server app1 10.0.1.11:80 check inter 10s fall 3 rise 2
    server app2 10.0.1.12:80 check inter 10s fall 3 rise 2
```

nginx: open-source nginx has only passive health checks (`max_fails` / `fail_timeout` on `upstream` servers). Active checks need NGINX Plus:

```nginx
upstream app {
    zone app 64k;
    server 10.0.1.11:80;
    server 10.0.1.12:80;
}

match lhe_ok {
    status 200;
}

server {
    location / {
        proxy_pass http://app;
        proxy_set_header Authorization "Bearer 4f3c2a1b9e8d7c6b5a49382716f5e4d3c2b1a09f8e7d6c5b4a3928170f6e5d4c";
        health_check uri=/lhe/v1/ping interval=10 fails=3 passes=2 match=lhe_ok;
    }
}
```

(The `proxy_set_header` above also adds the token to proxied app traffic. If that is not acceptable, use an IP allowlist for the load balancer instead.)

#### Frontend markers from outside

Every HTML page rendered through the `web` middleware group contains:

```html
<head><template id="lhe-service-up-header">UP</template>…</head>
<body>…<template id="lhe-service-up-footer">UP</template></body>
```

Check both:

```bash
page=$(curl -fsS https://app.example.com/) \
  && grep -q 'id="lhe-service-up-header"' <<<"$page" \
  && grep -q 'id="lhe-service-up-footer"' <<<"$page" \
  && echo "frontend OK" || echo "frontend BROKEN"
```

Keyword monitor (UptimeRobot, Better Stack, Uptime Kuma *HTTP(s) - Keyword*): URL `https://app.example.com/`, keyword `lhe-service-up-footer`. The footer marker is the stronger signal because it only exists if the whole page rendered.

| Markers found | Meaning |
|---|---|
| header **and** footer | Page rendered completely |
| header only | Rendering started but broke mid-page (exception in a view or component, truncated response) |
| none | App or frontend is down, the response was not HTML from the `web` group (error page, maintenance page, CDN error), or markers are disabled |

The markers are not behind the token or IP allowlist. They reveal nothing except "UP".

#### Polling guidance

| Endpoint | Use it for | Typical interval |
|---|---|---|
| `/lhe/v1/ping` | "Is the PHP app alive?" (liveness, load balancers, Docker) | 10–30 s |
| `/lhe/v1/{check}` or `/lhe/v1/{check}/{target}` | Targeted alerts per subsystem ("reporting DB down" goes to the DBA) | 60 s |
| `/lhe/v1` | Overall status (status page, readiness, one-monitor setups) | 60 s |

- **Interval vs. `LHE_CACHE_TTL`** (default 10 s): polling faster than the TTL only returns cached results (`"cached": true`). An interval of 30–60 s is plenty.
- **Interval vs. `LHE_RATE_LIMIT`** (default 60/min **per IP**): add up all monitors polling from the same IP (several endpoints, several monitors behind one NAT) and stay well below the limit, or raise it.
- **Monitor timeout:** every built-in target that talks to a server (each DB, Redis, cache store and queue target, plus `migrations` and `scheduler`) is bounded by `LHE_TIMEOUT` (default 3 s) for connecting and, where the driver supports it, again for reading, so a dead or hanging server costs at most about 2 × `LHE_TIMEOUT` per target. Checks run sequentially: set the monitor timeout above 2 × `LHE_TIMEOUT` × the number of such targets, plus time for custom checks. For example, 2 DB + 1 Redis + 1 cache target need more than 24 s in the worst case; use 25–30 s for `/lhe/v1`, or monitor per check. Per-check endpoints and `/ping` can use shorter timeouts. See [what `LHE_TIMEOUT` bounds](#what-lhe_timeout-bounds).


#### What `LHE_TIMEOUT` bounds

- **`database` and `migrations`:** each runs on a throwaway probe copy of the connection with a connect timeout of `LHE_TIMEOUT`. The probe is purged afterwards; your app's connection is never reused, reconnected or purged.
- **`redis`:** fresh probe connection with `timeout`/`read_timeout` = `LHE_TIMEOUT`.
- **Every check and target (built-in and custom):** while it runs, `default_socket_timeout` and `mysqlnd.net_read_timeout` are set to `LHE_TIMEOUT` and restored afterwards. This bounds predis/phpredis sockets without their own timeouts, PHP stream and file sockets, and MySQL/MariaDB reads on connections opened during the check (e.g. the `cache` check on a `redis` or `database` store, and heartbeat reads for `queue`/`scheduler`).

**Not bounded by the package:**

- Connections your app already opened earlier in the same request or Octane worker keep the timeouts they were created with (e.g. a `database` cache store or the Redis connection behind `LHE_HEARTBEAT_STORE`). Set `read_timeout`/`timeout` on those connections in `config/database.php` if a hanging server is a concern.
- PostgreSQL and SQL Server queries after the connect (no read timeout in PDO); a server that accepts connections but never answers can hang until PHP's `max_execution_time` or your web server's timeout.
- Code inside custom checks that does not use sockets (CPU-bound work, `sleep`). Use explicit timeouts on HTTP clients.

---

## 7. Environment variables reference

All variables are optional. Booleans accept `true`/`false`, `1`/`0`, `on`/`off`, `yes`/`no` (case-insensitive); an empty or invalid value falls back to the default, so a blank `LHE_*_ENABLED=` never disables anything. Invalid numbers also fall back to the default. Lists are comma-separated, with whitespace and empty entries ignored. Read only in `config/laravel-health-endpoints.php`, so `php artisan config:cache` works (re-run it after changing `.env`).

**General**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_TIMEOUT` | int (s, ≥ 1) | `3` | Connect timeout of the DB probes (`database`, `migrations`; `mysql`, `mariadb`, `pgsql`, `sqlsrv`), connect/read timeout of the Redis probes, and the socket timeout (`default_socket_timeout`, `mysqlnd.net_read_timeout`) while any check runs. See [what `LHE_TIMEOUT` bounds](#what-lhe_timeout-bounds). Custom checks should still set their own timeouts | `5` |

**Security**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_TOKEN` | string | empty (no token) | Shared token required on every `/lhe/v1*` request (Bearer header or `?token=`). Use ≥ 32 chars: `php artisan lhe:token` | `4f3c…6e5d4c` |
| `LHE_ALLOWED_IPS` | list | empty (all IPs) | IPv4/IPv6 addresses and CIDR ranges allowed to call the endpoints | `203.0.113.10,10.0.0.0/8,2001:db8::/32` |
| `LHE_RATE_LIMIT` | int (≥ 1) | `60` | Requests per minute per client IP | `30` |

**Caching**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_CACHE_TTL` | int (s, ≥ 0) | `10` | Result cache lifetime per URL. `0` disables the cache | `30` |
| `LHE_CACHE_STORE` | string | `file` | Cache store for results, check state (for transition logging), rate-limit counters and the public-warning flag. Deliberately not Redis/DB by default, since those may be what is down | `file` |
| `LHE_HEARTBEAT_STORE` | string | app default cache store | Cache store for scheduler/queue heartbeats. **Must be shared** by web, scheduler and worker processes | `redis` |

> Don't confuse `LHE_CACHE_TTL`/`LHE_CACHE_STORE` (the package's own result cache) with `LHE_CACHE_ENABLED`/`LHE_CACHE_STORES` (the built-in `cache` **check**).

**Logging**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_LOG_CHANNEL` | string | app default channel | Log channel for check failure/recovery entries | `stack` |
| `LHE_LOG_LLM_CHANNEL` | string | none | `llm.channel` context for `lexo/laravel-log-monitor` (e.g. a Mattermost channel name), on failures and recoveries | `monitoring` |
| `LHE_LOG_LLM_PRIORITY` | `important`\|`urgent` | none | `llm.priority` context on failure entries | `urgent` |
| `LHE_LOG_REMIND_MINUTES` | int (min, ≥ 0) | `0` | Re-log a still-failing check every N minutes. `0` = only log transitions | `60` |

**App info**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_SHOW_APP_INFO` | bool | `true` | Include the `app` block (name, environment, `composer.json` version) in responses | `false` |

**Database check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_DATABASE_ENABLED` | bool | `true` | Enable the `database` check | `false` |
| `LHE_DB_CONNECTIONS` | list | app default connection (`DB_CONNECTION`) | Connection names from `config/database.php` to probe | `mysql,reporting` |

**Redis check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_REDIS_ENABLED` | bool | `true` | Enable the `redis` check. **Set `false` if the app does not use Redis** | `false` |
| `LHE_REDIS_CONNECTIONS` | list | `default` | Redis connection names from `config/database.php` (`redis` section) | `default,cache` |

**Cache check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_CACHE_ENABLED` | bool | `true` | Enable the `cache` check | `false` |
| `LHE_CACHE_STORES` | list | app default store (`CACHE_STORE`) | Cache stores from `config/cache.php` to probe | `redis,file` |

**Storage check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_STORAGE_ENABLED` | bool | `true` | Enable the `storage` check | `false` |
| `LHE_STORAGE_DISKS` | list | app default disk (`FILESYSTEM_DISK`) | Disks from `config/filesystems.php` to probe | `local,s3` |

**Queue check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_QUEUE_ENABLED` | bool | `true` | Enable the `queue` check and its heartbeat job. **Set `false` without a queue worker** (or without a scheduler) | `false` |
| `LHE_QUEUE_CONNECTIONS` | list | app default connection and its default queue | `connection` or `connection:queue` entries | `redis,redis:emails` |
| `LHE_QUEUE_MAX_AGE` | int (min, ≥ 1) | `5` | Fail when the last queue heartbeat is older than this | `10` |

**Scheduler check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_SCHEDULER_ENABLED` | bool | `true` | Enable the `scheduler` check and its heartbeat. **Set `false` if nothing runs `schedule:run` / `schedule:work`** | `false` |
| `LHE_SCHEDULER_MAX_AGE` | int (min, ≥ 1) | `2` | Fail when the last scheduler heartbeat is older than this | `3` |

**Migrations check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_MIGRATIONS_ENABLED` | bool | `true` | Enable the `migrations` check | `false` |

**Debug-mode check**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_DEBUG_MODE_ENABLED` | bool | `true` | Enable the `debug-mode` check | `false` |
| `LHE_DEBUG_FORBIDDEN_ENVIRONMENTS` | list | `production` | Environments in which `APP_DEBUG=true` is a failure | `production,staging` |

**Markers**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_MARKERS_ENABLED` | bool | `true` | Auto-inject frontend markers into HTML responses of the `web` group | `false` |
| `LHE_MARKER_HEADER` | HTML string | `<template id="lhe-service-up-header">UP</template>` | Inserted right after the opening `<head…>` tag. Empty value = skip this marker | `'<meta name="up" content="1">'` |
| `LHE_MARKER_FOOTER` | HTML string | `<template id="lhe-service-up-footer">UP</template>` | Inserted right before the last `</body>`. Empty value = skip this marker | `'<template id="acme-up">UP</template>'` |

**Discovery**

| Variable | Type | Default | Description | Example |
|---|---|---|---|---|
| `LHE_DISCOVER_ENABLED` | bool | `true` | Auto-discover custom checks | `false` |
| `LHE_DISCOVER_PATH` | path | `app/HealthChecks` | Directory to scan (relative to the project root, or absolute) | `app/Support/Health` |
| `LHE_DISCOVER_NAMESPACE` | string | `App\HealthChecks` | PSR-4 namespace that maps to `LHE_DISCOVER_PATH` | `App\Support\Health` |

---

## 8. Built-in checks

All built-in checks are enabled by default. Disabling one (`LHE_<NAME>_ENABLED=false`) makes its route return `404`, removes it from `/lhe/v1` and, for `scheduler`/`queue`, stops registering its heartbeat. Failure messages below are exact strings. Messages derived from exceptions are mapped to safe texts (`Connection refused`, `Authentication failed`, `Timeout`, `Database not found`) or sanitized (credentials, passwords and paths removed). The raw exception goes to the log only.

### Database (`database`)

- **Verifies:** each configured connection accepts a query.
- **Probe:** `select 1` on a temporary clone of the connection config with a connect timeout of `LHE_TIMEOUT` seconds (`PDO::ATTR_TIMEOUT` for `mysql`/`mariadb`/`pgsql`, `login_timeout` for `sqlsrv`). The clone is purged afterwards, so your app's connection is never touched. Works with every Laravel driver.
- **Targets:** `LHE_DB_CONNECTIONS` (comma-separated), empty = `config('database.default')`.
- **Meta:** `driver` (from the connection config), `version` (server version).
- **Typical failures:**

| Message | Cause / what to do |
|---|---|
| `Connection refused` | Server down, wrong host/port, DNS failure, firewall |
| `Authentication failed` | Wrong username/password |
| `Timeout` | Server unreachable within `LHE_TIMEOUT` |
| `Database not found` | Database (or SQLite file) does not exist |
| `Connection not configured` | Name in `LHE_DB_CONNECTIONS` does not exist in `config/database.php` |

- **When to disable:** apps without a database.

### Redis (`redis`)

- **Verifies:** each configured Redis connection answers `PING`.
- **Probe:** `PING` over a fresh, temporary connection with `timeout`/`read_timeout` set to `LHE_TIMEOUT`, closed afterwards. Server version is read via `INFO server`.
- **Targets:** `LHE_REDIS_CONNECTIONS`, empty = `default`.
- **Meta:** `client` (`phpredis`/`predis`), `version` (Redis server version, if available).
- **Typical failures:** `Connection refused`, `Authentication failed`, `Timeout`, `Connection not configured` (name not in `config/database.php` → `redis`).
- **When to disable:** **the app does not use Redis** (`LHE_REDIS_ENABLED=false`). The `default` connection exists in Laravel's default config even when no Redis server runs, so the check would always fail.

### Cache (`cache`)

- **Verifies:** each configured cache store can write, read and delete.
- **Probe:** puts a random value under `lhe:probe:<random>` (TTL 10 s), reads it back, compares, and always deletes it.
- **Targets:** `LHE_CACHE_STORES`, empty = `config('cache.default')`.
- **Typical failures:** `Read-back mismatch` (value not stored, e.g. the `null` store or a full or evicting cache), `Store not configured`, mapped connection errors (`Connection refused`, …).
- **When to disable:** rarely. If the default store is `database`, the `cache` table must exist.

### Storage (`storage`)

- **Verifies:** each configured disk can write, read and delete.
- **Probe:** writes a random file to `.lhe-health/<random>.txt`, reads it back, compares, and always deletes it. The (empty) `.lhe-health/` directory stays. Never touches a disk that is not configured.
- **Targets:** `LHE_STORAGE_DISKS`, empty = `config('filesystems.default')`.
- **Typical failures:** `Write failed`, `Read-back mismatch`, `Disk not configured`, sanitized permission errors (paths replaced by `[path]`).
- **When to disable:** read-only filesystems by design.

### Queue (`queue`)

- **Verifies:** a worker actually processes jobs on each configured queue.
- **Probe:** every minute the **scheduler** dispatches a tiny `QueueHeartbeatJob` (no payload beyond the target name, `tries = 1`) to each target. When a worker runs it, it stores a timestamp in the heartbeat store (`LHE_HEARTBEAT_STORE`). The check reads that timestamp.
- **Targets:** `LHE_QUEUE_CONNECTIONS` with entries `connection` or `connection:queue` (e.g. `redis,redis:emails`), empty = `config('queue.default')` and its default queue. In URLs: `/lhe/v1/queue/redis:emails`.
- **Meta:** `last_heartbeat` (ISO 8601).
- **Prerequisites:** **scheduler running** (`schedule:run` via cron or `schedule:work`) **and** a worker processing that queue (`queue:work`, Horizon, …). The heartbeat store must be shared between scheduler, worker and web processes.
- **Messages:**

| Message | Status | Cause / what to do |
|---|---|---|
| `No queue heartbeat yet — are the scheduler and a queue worker running?` | fail | Normal for the first minute after deploy. Otherwise start the scheduler and a worker for this queue, or check `LHE_HEARTBEAT_STORE` |
| `Last queue heartbeat 7 min ago (max 5)` | fail | Worker stopped, stuck or backlogged. Restart workers or raise `LHE_QUEUE_MAX_AGE` |
| `Connection not configured` | fail | Connection name not in `config/queue.php` |
| `sync driver — no worker required` | ok | `sync` connections run jobs inline; no heartbeat needed |
| `null driver` | ok | `null` connections discard jobs |

- **When to disable:** **no queue worker** (`LHE_QUEUE_ENABLED=false`). Note that new Laravel apps default to `QUEUE_CONNECTION=database`, which needs a worker.

### Scheduler (`scheduler`)

- **Verifies:** the Laravel scheduler runs.
- **Probe:** the package registers an every-minute scheduled callback named `lhe:scheduler-heartbeat` that stores a timestamp in the heartbeat store. The check reads it. It shows up in `php artisan schedule:list`.
- **Meta:** `last_heartbeat` (ISO 8601).
- **Prerequisites:** cron entry `* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1`, or `php artisan schedule:work`.
- **Messages:**

| Message | Cause / what to do |
|---|---|
| `No scheduler heartbeat yet — is schedule:run / schedule:work running?` | Normal for the first minute after deploy. Otherwise add the cron entry, or check that `LHE_HEARTBEAT_STORE` is shared |
| `Last scheduler heartbeat 4 min ago (max 2)` | Cron stopped or the scheduler is blocked by a long-running task. Fix cron, use `->runInBackground()` for long tasks, or raise `LHE_SCHEDULER_MAX_AGE` |

- **When to disable:** **nothing runs the scheduler** (`LHE_SCHEDULER_ENABLED=false`). In that case also disable `queue`, because its heartbeat job is dispatched by the scheduler.

### Migrations (`migrations`)

- **Verifies:** every migration file has been run on the default connection.
- **Probe:** compares the files in `database/migrations` (plus paths registered by packages via `loadMigrationsFrom`) with the `migrations` table, read through a throwaway probe copy of the migrator's connection (default connection) with a connect timeout of `LHE_TIMEOUT`. Your app's connection is never touched. Exception: an in-memory SQLite database (`:memory:` or `mode=memory`, e.g. Laravel's default `phpunit.xml`) only exists on the app's own connection, so it is read there.
- **Meta:** `pending` (count).
- **Messages:** `Migrations table not found` (run `php artisan migrate`), `Connection not configured` (the default connection is missing from `config/database.php`), `2 pending migration(s): 2026_10_01_000000_create_orders_table, 2026_10_02_000000_add_status_to_orders_table` (up to three names, then `, …`). If the default DB is down, the mapped connection error (e.g. `Connection refused`).
- **When to disable:** apps without a database or without migrations.

### Debug mode (`debug-mode`)

- **Verifies:** `APP_DEBUG` is not enabled in an environment where it must not be.
- **Probe:** reads `config('app.debug')` and the environment.
- **Meta:** `debug` (bool).
- **Message:** `APP_DEBUG is enabled in production`. Set `APP_DEBUG=false` and re-cache config.
- **Configuration:** `LHE_DEBUG_FORBIDDEN_ENVIRONMENTS` (default `production`).
- **When to disable:** practically never.

---

## 9. Frontend markers

**What gets injected where.** The `InjectServiceMarkers` middleware is appended to the `web` middleware group. For every eligible response it inserts:

- the **header marker** `<template id="lhe-service-up-header">UP</template>` immediately after the first opening `<head …>` tag (case-insensitive, attributes allowed);
- the **footer marker** `<template id="lhe-service-up-footer">UP</template>` immediately before the **last** `</body>` (case-insensitive, so a `</body>` string inside an inline script earlier in the page is ignored).

`<template>` content is never rendered, so users see nothing. If an anchor tag is missing, that marker is skipped. If the exact marker string is already in the page (for example via a Blade directive), it is not added again. When the response had a `Content-Length` header, it is recalculated, and an `ETag` header is removed because it no longer matches the body.

**Skipped responses.** Markers are only injected into responses that are:

- `Illuminate\Http\Response` instances (not `StreamedResponse`, `BinaryFileResponse` or `JsonResponse`);
- status `2xx` other than `204` (no error pages, redirects or empty responses);
- `Content-Type: text/html…`;
- not compressed (no `Content-Encoding` header);
- not downloads (`Content-Disposition: attachment`);
- non-empty;
- routed through the `web` middleware group (routes in `api` or custom groups are never touched).

**Overriding the markup.** Use single quotes in `.env` so the double quotes inside stay literal:

```dotenv
LHE_MARKER_HEADER='<meta name="acme-status" content="up">'
LHE_MARKER_FOOTER='<template id="acme-render-complete">UP</template>'
```

For multi-line HTML, publish the config and set `markers.header` / `markers.footer` there (see [2a](#2a-configuration-env-vars-or-published-config)). An empty value skips that marker. Marker HTML comes only from config and is never built from request data. It is output unescaped, so put only trusted HTML there.

**Disabling auto-injection:**

```dotenv
LHE_MARKERS_ENABLED=false
```

**Blade directives** for apps that disable auto-injection or have non-standard layouts. They print the configured markers:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    @lheHeaderMarker
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
</head>
<body>
    {{ $slot }}
    @lheFooterMarker
</body>
</html>
```

**How a monitor should scrape them:** request a real page (e.g. `/` or `/login`) and require **both** marker IDs (or your custom markup). See [Frontend markers from outside](#frontend-markers-from-outside) for the `curl` command and the meaning of partial results.

---

## 10. Security

**Token.**

- `LHE_TOKEN` empty means no token is required. When set, every `/lhe/v1*` request (including `ping`) needs it. A non-string `token` value in the published config (array, int, object) fails closed: every request is denied with `401`.
- Send it as `Authorization: Bearer <token>` (**recommended**) or `?token=<token>`. Query strings are written to web server, proxy and CDN access logs.
- Compared with `hash_equals` (constant time). Never logged, echoed, cached or put in response bodies.
- Generate one with `php artisan lhe:token`: 64 hex characters (256 bits) from `random_bytes`. It is printed only and nothing is written to disk. `lhe:routes` warns about tokens shorter than 32 characters.
- Wrong or missing token returns `401` `{"status":"fail","message":"Unauthorized"}`. This is not logged as a health failure.

**IP allowlist and trusted proxies.**

- `LHE_ALLOWED_IPS` empty means any IP. When set, only listed addresses and ranges (IPv4, IPv6, CIDR via Symfony `IpUtils`) get through; all others get `403` `{"status":"fail","message":"Forbidden"}`.
- The client IP is `$request->ip()`. The package never reads `X-Forwarded-For` itself. Behind a load balancer or reverse proxy, configure Laravel's trusted proxies (see [Quick start, setup 3](#3-quick-start)). Without that, every request has the proxy's IP. Also make sure the proxy overwrites, rather than appends to, a client-supplied `X-Forwarded-For`.
- Token and allowlist can be combined. Then both are required: IP is checked first (`403`), then the token (`401`).

**Rate limiting.**

- `LHE_RATE_LIMIT` requests per minute per client IP (default 60). It runs **before** the IP and token checks to slow down token brute-forcing. Over the limit: `429` with `Retry-After`.
- Uses Laravel's rate limiter on the package's own store, `LHE_CACHE_STORE` (`file` by default), never the app's default or `cache.limiter` store, so a slow or down database/Redis cache cannot stall `ping`. Counters are therefore per server unless `LHE_CACHE_STORE` points at a shared store. If that store is unavailable, the limiter **fails open**: requests are not throttled, but the IP allowlist and token are still enforced. A cache outage cannot take down your health endpoints.

**Order of evaluation:** rate limit → IP allowlist → token → routing (404/405) → checks. Unauthenticated callers cannot enumerate checks or targets: an unknown check with a bad token returns `401`, not `404`.

**What is never exposed.**

- Responses are the same with or without protection. Without `LHE_TOKEN` or `LHE_ALLOWED_IPS`, anyone can read messages, server versions, the `app` block and target names.
- Messages are always sanitized: first line only, max 255 characters, `user:pass@` in URLs, `password=`/`secret=`/`token=`/`key=`-style values and absolute file paths are removed. Known exceptions are mapped to fixed texts. Stack traces and raw exceptions go to the log only.
- Package routes never render Laravel's debug error page, even with `APP_DEBUG=true`. Any unexpected error becomes `503` `{"status":"fail","message":"Health check error"}`.
- No CORS headers, no session, no cookies, no CSRF. Responses carry `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex, nofollow`.
- URL input is only ever compared against registered check names and configured targets. It is never used to build queries, file paths, class names or cache keys.
- Probes are read-only or self-cleaning: DB `select 1`; cache key `lhe:probe:*` with short TTL, deleted in `finally`; storage file in `.lhe-health/`, deleted in `finally`; queue heartbeat job without payload.

**Known trade-off in public mode.** Without protection, anyone can read connection, store and disk names, server versions, the app version and failure messages. **Set `LHE_TOKEN` or `LHE_ALLOWED_IPS` in production.**

**Misconfiguration warnings.** In production with neither token nor allowlist, `php artisan lhe:routes` prints a warning and the package logs this `warning` at most once per 24 hours (flag stored in `LHE_CACHE_STORE`):

```
[LHE] Health endpoints are public and expose check details. Set LHE_TOKEN or LHE_ALLOWED_IPS.
```

**Hardening checklist**

- [ ] `LHE_TOKEN` set to a value from `php artisan lhe:token` (≥ 32 chars), sent via `Authorization: Bearer` header.
- [ ] `LHE_ALLOWED_IPS` limited to your monitoring IPs / internal network where possible.
- [ ] Trusted proxies configured when behind a load balancer, CDN or reverse proxy.
- [ ] `LHE_RATE_LIMIT` sized for your monitors (and no higher).
- [ ] `php artisan lhe:routes` shows no warnings.
- [ ] Custom checks put no secrets in `message`, `meta` or target names.
- [ ] Access logs scrubbed of `token=` if any tool must use the query parameter.
- [ ] `php artisan config:cache` and `php artisan route:cache` in production.

---

## 11. Logging and events

### State-transition logic

The package logs on **state transitions only**, per check and per target. The last state is stored in `LHE_CACHE_STORE`; an unknown previous state counts as `ok`. Transitions are only evaluated on real (uncached) runs, including `php artisan lhe:run`.

| Previous → current | Log | Event |
|---|---|---|
| `ok` → `fail` | `error`: `[LHE] {check}[:{target}] check failed: {message}` | `HealthCheckFailed` |
| `fail` → `ok` | `info`: `[LHE] {check}[:{target}] check recovered` (with `llm.alert = true`) | `HealthCheckRecovered` |
| `fail` → `fail` | nothing, or the same `error` again every `LHE_LOG_REMIND_MINUTES` (if > 0) | none |
| `ok` → `ok` | nothing | none |

**Example timeline** (monitor polls every minute, `LHE_CACHE_TTL=10`, Redis goes down at 10:02 and comes back at 10:47):

```
10:00  redis ok                      → nothing
10:01  redis ok                      → nothing
10:02  redis:default fail            → ERROR [LHE] redis:default check failed: Connection refused
                                       + HealthCheckFailed
10:03 … 10:46  still failing         → nothing (LHE_LOG_REMIND_MINUTES=0)
10:47  redis:default ok              → INFO  [LHE] redis:default check recovered
                                       + HealthCheckRecovered
```

**Real log lines** (`LHE_LOG_LLM_CHANNEL=monitoring`, `LHE_LOG_LLM_PRIORITY=urgent`; exception text shortened):

```
[2026-10-30 15:28:21] production.ERROR: [LHE] database:reporting check failed: Database not found {"check":"database","target":"reporting","message":"Database not found","meta":[],"duration_ms":0.68,"exception":"[object] (Illuminate\\Database\\QueryException(code: 0): Database file at path [...] does not exist. ...)","llm":{"channel":"monitoring","priority":"urgent"}}
[2026-10-30 15:28:22] production.INFO: [LHE] database:reporting check recovered {"check":"database","target":"reporting","message":null,"meta":{"driver":"sqlite","version":"3.54.1"},"duration_ms":0.15,"llm":{"alert":true,"channel":"monitoring"}}
```

Checks without targets log without the `:{target}` part:

```
[2026-10-30 15:23:41] production.ERROR: [LHE] scheduler check failed: No scheduler heartbeat yet — is schedule:run / schedule:work running? {"check":"scheduler","target":null,"message":"No scheduler heartbeat yet — is schedule:run / schedule:work running?","meta":[],"duration_ms":0.04,"llm":[]}
[2026-10-30 15:23:53] production.INFO: [LHE] scheduler check recovered {"check":"scheduler","target":null,"message":null,"meta":{"last_heartbeat":"2026-10-30T15:23:53+00:00"},"duration_ms":0.06,"llm":{"alert":true}}
```

**Context keys:** `check`, `target` (`null` for checks without targets), `message` (sanitized), `meta`, `duration_ms`, `exception` (failures caused by an exception; the raw exception, log only), `llm`.

**Channel:** `LHE_LOG_CHANNEL`, empty = app default. Unexpected internal errors of the endpoint itself are logged on the default channel as `[LHE] Health endpoint error` (with `exception`, `method`, `path`; never the query string), at most once per minute per exception class (flag in `LHE_CACHE_STORE`; if that store is down, every occurrence is logged).

### `llm` context and `lexo/laravel-log-monitor`

[`lexo/laravel-log-monitor`](https://github.com/lexo-ch/laravel-log-monitor) forwards `error` entries by default and other levels only with `llm.alert = true`. The package fills the `llm` block so both directions arrive:

| Entry | `llm` content |
|---|---|
| failure (`error`) | `channel` (`LHE_LOG_LLM_CHANNEL`), `priority` (`LHE_LOG_LLM_PRIORITY`). Empty values are omitted |
| recovery (`info`) | `alert: true`, `channel` (`LHE_LOG_LLM_CHANNEL`) |

```dotenv
LHE_LOG_LLM_CHANNEL=monitoring   # a channel name configured in laravel-log-monitor's additional_channels
LHE_LOG_LLM_PRIORITY=urgent      # important | urgent
```

The `llm` key is harmless when log-monitor is not installed.

### Events

`LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed` and `LEXO\LaravelHealthEndpoints\Events\HealthCheckRecovered` are dispatched on the same transitions as the log entries (not on reminders). Readonly public properties:

| Property | Type | Meaning |
|---|---|---|
| `$check` | `string` | Check name, e.g. `database` |
| `$target` | `?string` | Target name, e.g. `reporting`, or `null` |
| `$result` | `LEXO\LaravelHealthEndpoints\Support\CheckResult` | `status`, sanitized `message`, `meta` |
| `$durationMs` | `float` | Run time in milliseconds |

Listener example (auto-discovered by Laravel in `app/Listeners`):

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notifications\HealthCheckAlert;
use Illuminate\Support\Facades\Notification;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed;

class SendHealthCheckAlert
{
    public function handle(HealthCheckFailed $event): void
    {
        $label = $event->target === null ? $event->check : "{$event->check}:{$event->target}";

        Notification::route('mail', 'ops@example.com')
            ->notify(new HealthCheckAlert($label, $event->result->message ?? 'failed'));
    }
}
```

Exceptions thrown by listeners are swallowed so they never break the health endpoint. Make listeners `ShouldQueue` if they do slow work.

---

## 12. Writing a custom check

A custom check is one class: extend `LEXO\LaravelHealthEndpoints\Checks\Check`, implement `run()`, and return `CheckResult::ok()` / `CheckResult::fail()` or throw.

### Step by step

**1. Generate**

```bash
php artisan make:health-check DiskSpace            # app/HealthChecks/DiskSpaceCheck.php
php artisan make:health-check PaymentGateways --targets   # variant with multiple targets
```

**2. Implement** `run()` (see the examples below). Files in `app/HealthChecks` are discovered automatically; no registration needed.

**3. Verify**

```bash
php artisan lhe:run disk-space
php artisan lhe:routes
curl -H "Authorization: Bearer $LHE_TOKEN" https://app.example.com/lhe/v1/disk-space
```

**4. Test** (see [Testing your checks](#14-testing-your-checks)).

### Simple check

```php
<?php

declare(strict_types=1);

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

### Check with targets (`HasTargets`)

Extend `LEXO\LaravelHealthEndpoints\Checks\TargetedCheck` (it implements `HasTargets` and provides `run()` for you). Each target gets its own URL, status, log line and state:

```php
<?php

declare(strict_types=1);

namespace App\HealthChecks;

use Illuminate\Support\Facades\Http;
use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

class ExternalApiCheck extends TargetedCheck
{
    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return ['payments', 'shipping'];
    }

    public function runTarget(string $target): CheckResult
    {
        $url = config("services.{$target}.health_url");

        if (! is_string($url) || $url === '') {
            return CheckResult::fail('Health URL not configured');
        }

        $response = Http::timeout(2)->get($url);

        return $response->successful()
            ? CheckResult::ok(meta: ['http_status' => $response->status()])
            : CheckResult::fail("HTTP {$response->status()}", ['http_status' => $response->status()]);
    }
}
```

→ `GET /lhe/v1/external-api` (all targets), `GET /lhe/v1/external-api/payments`, `GET /lhe/v1/external-api/shipping`. Any other target returns `404`. Target names may contain letters, digits and `. _ : -` (max 128 characters).

`php artisan lhe:run external-api`:

```
+--------------+----------+--------+---------------+---------------------------+
| Check        | Target   | Status | Duration (ms) | Message                   |
+--------------+----------+--------+---------------+---------------------------+
| external-api | payments | ok     | 49            |                           |
| external-api | shipping | fail   | 0             | Health URL not configured |
+--------------+----------+--------+---------------+---------------------------+
```

### Check with its own path (`HasCustomRoute`) and a custom name

```php
<?php

declare(strict_types=1);

namespace App\HealthChecks;

use Illuminate\Support\Facades\Http;
use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

class StripeCheck extends Check implements HasCustomRoute
{
    // Without this the name would be "stripe" (derived from the class name).
    protected ?string $name = 'stripe-api';

    public function path(): string
    {
        return 'billing/stripe'; // relative to /lhe/v1
    }

    public function run(): CheckResult
    {
        $response = Http::timeout(2)->get(config('services.stripe.status_url', 'https://status.stripe.com/current'));

        return $response->successful()
            ? CheckResult::ok('Stripe reachable')
            : CheckResult::fail("Stripe returned HTTP {$response->status()}");
    }
}
```

→ `GET /lhe/v1/billing/stripe` **and** `GET /lhe/v1/stripe-api`, same middleware and response format. Paths match `[a-z0-9-]+(/[a-z0-9-]+)*`. The **first segment must not equal any check name or reserved name** (`billing/stripe` is fine; `database/stripe` or `stripe-api/status` is rejected). After adding or changing a custom path, re-run `php artisan route:cache` if you cache routes.

### Implementing `HealthCheck` directly

Extending `Check` is optional. Any class implementing `LEXO\LaravelHealthEndpoints\Contracts\HealthCheck` works (add `HasTargets` and/or `HasCustomRoute` as needed); you then provide `name()` yourself:

```php
<?php

declare(strict_types=1);

namespace App\HealthChecks;

use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class LicenseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'license'; // must follow the naming rules below
    }

    public function run(): CheckResult
    {
        return now()->lt(config('services.license.expires_at'))
            ? CheckResult::ok()
            : CheckResult::fail('License expired');
    }
}
```

→ `GET /lhe/v1/license`.

### Constructor injection

Checks are resolved from the container, so constructor dependencies are injected:

```php
<?php

declare(strict_types=1);

namespace App\HealthChecks;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

class QueueBacklogCheck extends Check
{
    public function __construct(private readonly QueueFactory $queue) {}

    public function run(): CheckResult
    {
        $size = $this->queue->connection('redis')->size('default');

        return $size < 1000
            ? CheckResult::ok("{$size} jobs waiting", ['size' => $size])
            : CheckResult::fail("Queue backlog too large: {$size} jobs waiting", ['size' => $size]);
    }
}
```

→ `GET /lhe/v1/queue-backlog`.

### Registering checks outside `app/HealthChecks`

1. **Auto-discovery** (default): every non-abstract class implementing `HealthCheck` below `LHE_DISCOVER_PATH` (`app/HealthChecks`), mapped PSR-4 onto `LHE_DISCOVER_NAMESPACE` (`App\HealthChecks`). Subdirectories work (`app/HealthChecks/Billing/StripeCheck.php` → `App\HealthChecks\Billing\StripeCheck`).
2. **Config:** publish the config and list the class in `checks`:

   ```php
   'checks' => [
       App\Support\Health\BillingCheck::class,
   ],
   ```

3. **Programmatic**, e.g. from another package's service provider:

   ```php
   <?php

   declare(strict_types=1);

   namespace Acme\Billing;

   use Acme\Billing\Health\BillingCheck;
   use Illuminate\Support\ServiceProvider;
   use LEXO\LaravelHealthEndpoints\Facades\LaravelHealthEndpoints;

   class BillingServiceProvider extends ServiceProvider
   {
       public function boot(): void
       {
           LaravelHealthEndpoints::register(BillingCheck::class); // class name or instance
       }
   }
   ```

The same class registered several ways is only added once.

### Naming rules

- The name is derived from the class name: basename, minus the `Check` suffix, kebab-cased. `DiskSpaceCheck` → `disk-space`, `QueueBacklogCheck` → `queue-backlog`, `ApiV2Check` → `api-v2`.
- Override it with `protected ?string $name = 'my-name';` (the property is **nullable**: `?string`), or override `name()`. Do this for acronyms: `HTTPStatusCheck` would become `h-t-t-p-status`.
- A name must match `^[a-z][a-z0-9]*(-[a-z0-9]+)*$`: start with a lowercase letter; lowercase letters, digits and single hyphens; no leading, trailing or double hyphens.
- **Reserved names:** `ping`, `database`, `redis`, `cache`, `storage`, `queue`, `scheduler`, `migrations`, `debug-mode`. Names must be unique.
- `make:health-check` accepts only StudlyCase names (`^[A-Z][A-Za-z0-9]*$`, no subdirectories, dots or underscores), adds the `Check` suffix if missing, and rejects a bare `Check` and names that would derive a reserved name.
- Invalid, reserved or duplicate names and invalid or colliding paths throw `LEXO\LaravelHealthEndpoints\Exceptions\InvalidHealthCheckException` with a message that says what to rename. The endpoints then answer `503` `Health check error`. `php artisan lhe:routes` and `php artisan lhe:run` print the message.

### Do / don't

| Do | Don't |
|---|---|
| Keep checks fast: finish well within `LHE_TIMEOUT` (3 s); set explicit timeouts on HTTP/socket calls | Long-running work, unbounded loops, large queries |
| Return `CheckResult::fail('…')` or simply throw: any exception becomes a sanitized `fail` | `echo`, `dd()`, `exit` or writing to the response |
| Put useful, non-sensitive numbers in `meta` (`['percent_used' => 58]`) | Secrets, tokens, DSNs, passwords, personal data in `message`, `meta` or target names |
| Make probes read-only or self-cleaning | Side effects (sending mail, charging cards, writing business data) |
| Inject dependencies via the constructor | Catch every exception and return `ok` |

`duration_ms` is measured by the package; do not measure it yourself. String values in `meta` are sanitized like messages. Invalid UTF-8 in `meta` keys and target names is replaced with `?`, and `NAN`/`INF` become `null`, so one odd value never turns the whole report into a `503`.

**Laravel Octane / long-running workers.** Check instances are resolved once and memoized per worker (the registry is a singleton), so the same object serves many requests. Keep checks stateless: no per-request data in properties, and do not inject request-scoped services (`Request`, the authenticated user, session) into the constructor. Resolve anything per-run inside `run()` / `runTarget()` instead.

---

## 13. Artisan commands

### `make:health-check`

```
php artisan make:health-check {name} [--targets] [--force]
```

Creates `app/HealthChecks/{Name}Check.php` from a heavily commented stub (published stubs in `stubs/lhe/` take precedence). `--targets` generates a `TargetedCheck`; `--force` overwrites.

```console
$ php artisan make:health-check DiskSpace

   INFO  Health check [app/HealthChecks/DiskSpaceCheck.php] created successfully.

$ php artisan make:health-check PaymentGateways --targets

   INFO  Health check [app/HealthChecks/PaymentGatewaysCheck.php] created successfully.

$ php artisan make:health-check DiskSpace

   ERROR  Health check already exists.

$ php artisan make:health-check Redis

   ERROR  The check name "redis" is reserved (ping, database, redis, cache, storage, queue, scheduler, migrations, debug-mode). Choose another name.

$ php artisan make:health-check billing/Stripe

   ERROR  The name must be StudlyCase (letters and digits, starting with a capital letter); no paths, dots or underscores.
```

Exit code `0` on success, `1` on any error.

### `lhe:routes`

```
php artisan lhe:routes [--json]
```

Lists every endpoint with method, full URL (based on `APP_URL`), check, target and active protection (`none`, `token`, `ip allowlist`, `token + ip allowlist`). Prints warnings for public production endpoints, a non-string token in the published config (every request is denied), tokens shorter than 32 characters, and an `LHE_CACHE_STORE` or `LHE_HEARTBEAT_STORE` that is not a store in `config/cache.php` (both fail silently at runtime: no rate limiting, result cache or state tracking, respectively failing scheduler/queue checks). Exit code `0` (`1` if a check registration is invalid; the message is printed).

```console
$ php artisan lhe:routes
+----------+---------------------------------------------------+------------+-----------+------------+
| Method   | URL                                               | Check      | Target    | Protection |
+----------+---------------------------------------------------+------------+-----------+------------+
| GET|HEAD | https://app.example.com/lhe/v1                    | -          | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/ping               | -          | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/database           | database   | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/database/sqlite    | database   | sqlite    | none       |
| GET|HEAD | https://app.example.com/lhe/v1/database/reporting | database   | reporting | none       |
| GET|HEAD | https://app.example.com/lhe/v1/redis              | redis      | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/redis/default      | redis      | default   | none       |
| GET|HEAD | https://app.example.com/lhe/v1/cache              | cache      | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/cache/file         | cache      | file      | none       |
| GET|HEAD | https://app.example.com/lhe/v1/storage            | storage    | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/storage/local      | storage    | local     | none       |
| GET|HEAD | https://app.example.com/lhe/v1/queue              | queue      | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/queue/redis        | queue      | redis     | none       |
| GET|HEAD | https://app.example.com/lhe/v1/scheduler          | scheduler  | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/migrations         | migrations | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/debug-mode         | debug-mode | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/disk-space         | disk-space | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/stripe-api         | stripe-api | -         | none       |
| GET|HEAD | https://app.example.com/lhe/v1/billing/stripe     | stripe-api | -         | none       |
+----------+---------------------------------------------------+------------+-----------+------------+
Health endpoints are PUBLIC in production (no LHE_TOKEN and no LHE_ALLOWED_IPS): check details, versions and connection names are exposed. Set a token or an IP allowlist.
```

`--json` (excerpt; `routes` lists every row as above):

```json
{
    "routes": [
        {
            "method": "GET|HEAD",
            "url": "https://app.example.com/lhe/v1",
            "check": null,
            "target": null,
            "protection": "token"
        },
        {
            "method": "GET|HEAD",
            "url": "https://app.example.com/lhe/v1/database/reporting",
            "check": "database",
            "target": "reporting",
            "protection": "token"
        }
    ],
    "warnings": [
        "LHE_TOKEN is shorter than 32 characters. Generate a strong one with `php artisan lhe:token`."
    ]
}
```

### `lhe:run`

```
php artisan lhe:run [check] [target] [--json]
```

Runs checks from the CLI without HTTP and **without** the result cache. State transitions are still recorded, so failures and recoveries are logged and events fire. `--json` prints the same report as the HTTP endpoint.

| Exit code | Meaning |
|---|---|
| `0` | All checks run passed |
| `1` | At least one check failed, or a check registration is invalid |
| `2` | Unknown check or target |

```console
$ php artisan lhe:run
+------------+---------+--------+---------------+---------+
| Check      | Target  | Status | Duration (ms) | Message |
+------------+---------+--------+---------------+---------+
| database   | sqlite  | ok     | 3             |         |
| redis      | default | ok     | 6             |         |
| cache      | file    | ok     | 1             |         |
| storage    | local   | ok     | 6             |         |
| queue      | redis   | ok     | 0             |         |
| scheduler  | -       | ok     | 0             |         |
| migrations | -       | ok     | 5             |         |
| debug-mode | -       | ok     | 0             |         |
+------------+---------+--------+---------------+---------+

$ php artisan lhe:run database reporting
+----------+-----------+--------+---------------+---------+
| Check    | Target    | Status | Duration (ms) | Message |
+----------+-----------+--------+---------------+---------+
| database | reporting | ok     | 3             |         |
+----------+-----------+--------+---------------+---------+

$ php artisan lhe:run redis        # exit code 1
+-------+---------+--------+---------------+--------------------+
| Check | Target  | Status | Duration (ms) | Message            |
+-------+---------+--------+---------------+--------------------+
| redis | default | fail   | 3             | Connection refused |
+-------+---------+--------+---------------+--------------------+

$ php artisan lhe:run database mysql        # exit code 2

   ERROR  Target "mysql" is not configured for health check "database".

$ php artisan lhe:run nope                  # exit code 2

   ERROR  Unknown health check "nope". Available: database, redis, cache, storage, queue, scheduler, migrations, debug-mode, disk-space, stripe-api.

$ php artisan lhe:run disk-space --json
{
    "status": "ok",
    "timestamp": "2026-10-30T15:25:12+00:00",
    "duration_ms": 2,
    "cached": false,
    "app": {
        "name": "Acme",
        "environment": "production",
        "version": null
    },
    "checks": {
        "disk-space": {
            "status": "ok",
            "duration_ms": 0,
            "message": "58% used",
            "meta": {
                "percent_used": 58
            }
        }
    }
}
```

### `lhe:token`

```
php artisan lhe:token [--show-env]
```

Prints a random 64-character hex token. It never writes files.

```console
$ php artisan lhe:token
bab5d0768945afffef5c9940a39a38751727b75dfbd752d4385b5a3fc804ecbb

$ php artisan lhe:token --show-env
LHE_TOKEN=3af56eed47548b44ba37a564fd63c31a7aabbd919e2b6d998961f2760250e726
```

---

## 14. Testing your checks

Recommended `phpunit.xml` entries, so results and states from earlier test runs (stored in the `file` cache by default) do not leak into your tests:

```xml
<env name="LHE_CACHE_TTL" value="0"/>
<env name="LHE_CACHE_STORE" value="array"/>
```

`tests/Feature/HealthChecks/DiskSpaceCheckTest.php`:

```php
<?php

declare(strict_types=1);

use App\HealthChecks\DiskSpaceCheck;
use LEXO\LaravelHealthEndpoints\Support\Status;

it('reports disk usage', function () {
    $result = app(DiskSpaceCheck::class)->run();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta)->toHaveKey('percent_used');
});

it('is exposed over HTTP', function () {
    $this->getJson('/lhe/v1/disk-space')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.disk-space.status', 'ok')
        ->assertJsonStructure(['checks' => ['disk-space' => ['message', 'meta' => ['percent_used']]]]);
});

it('passes on the CLI', function () {
    $this->artisan('lhe:run', ['check' => 'disk-space'])->assertExitCode(0);
});
```

Testing the failure path with a mocked dependency, including the event, in `tests/Feature/HealthChecks/QueueBacklogCheckTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Event;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed;

it('fails and dispatches HealthCheckFailed when the backlog is too large', function () {
    Event::fake([HealthCheckFailed::class]);

    $this->mock(QueueFactory::class, function ($mock) {
        $mock->shouldReceive('connection->size')->andReturn(5000);
    });

    $this->getJson('/lhe/v1/queue-backlog')
        ->assertStatus(503)
        ->assertJsonPath('checks.queue-backlog.status', 'fail');

    Event::assertDispatched(HealthCheckFailed::class, fn (HealthCheckFailed $event) => $event->check === 'queue-backlog');
});
```

Tips:

- Test per-check URLs (`/lhe/v1/disk-space`) rather than `/lhe/v1`, which also runs the built-in checks against your test environment.
- Mock dependencies **before** the first request; the check is resolved from the container when the registry is first used.
- Unit-test `runTarget('…')` directly for checks with targets.

---

## 15. Troubleshooting / FAQ

### The queue check fails right after installing

`No queue heartbeat yet — are the scheduler and a queue worker running?`

- The heartbeat job is dispatched by the **scheduler** every minute and handled by a **worker**. Both must run: `php artisan schedule:list` should show `lhe:queue-heartbeat:{connection}:{queue}`, and a worker must process that queue (`php artisan queue:work redis --queue=default`).
- Wait one to two minutes after deploy.
- New Laravel apps use `QUEUE_CONNECTION=database`. With no worker at all, set `LHE_QUEUE_ENABLED=false`.
- Check that the heartbeat store is shared (see below).

### The scheduler check fails

`No scheduler heartbeat yet — is schedule:run / schedule:work running?` means nothing runs `schedule:run`, or the heartbeat store is not shared. Add the cron entry, or set `LHE_SCHEDULER_ENABLED=false` (and `LHE_QUEUE_ENABLED=false`).

### Multi-server setups: heartbeats missing on some servers

`LHE_HEARTBEAT_STORE` defaults to the app's default cache store. If that is `file`, each server has its own heartbeats: the server running cron has one, web servers do not. Set a **shared** store:

```dotenv
LHE_HEARTBEAT_STORE=redis   # or database, memcached, dynamodb
```

If that store goes down, the scheduler and queue checks fail as well.

Similarly, `LHE_CACHE_STORE` (`file` by default) is per server. Each server tracks its own state and rate-limit counters, so with N web servers a failure may be logged up to N times and a client may make up to N × `LHE_RATE_LIMIT` requests per minute. Point `LHE_CACHE_STORE` at a shared store if you prefer one entry, accepting that it then depends on that store (results are computed uncached and state is not tracked while it is down).

### `403 Forbidden` behind a load balancer

`$request->ip()` returns the proxy's IP because Laravel does not trust the proxy. Configure trusted proxies in `bootstrap/app.php` (`$middleware->trustProxies(at: [...])`, see [Quick start](#3-quick-start)), or allowlist the proxy's IP range if the load balancer itself is the client (ALB health checks).

### `401 Unauthorized` although the token is correct

- `.env` changed but the config is cached: run `php artisan config:cache` (or `config:clear`).
- Apache/PHP-FPM can drop the `Authorization` header. Laravel's default `public/.htaccess` contains the rewrite rule that passes it on, so make sure it is still there, or use `?token=` temporarily to confirm.
- A proxy, CDN or WAF strips `Authorization`.
- Whitespace or quotes in `.env`: the value is trimmed, but quotes inside the value are not.

### Every `/lhe/v1` URL returns `404` (or an HTML page)

- An app-level catch-all route (e.g. `Route::get('{any}', ...)->where('any', '.*')` for a SPA, or a CMS fallback) registered earlier shadows `/lhe/v1`, because the package registers its routes after the app has booted. Exclude the prefix in your catch-all: `->where('any', '(?!lhe/v1).*')`.
- Stale route cache after adding a check with a custom path: `php artisan route:cache`.
- The check is disabled (`LHE_<NAME>_ENABLED=false`), or the target is not in the configured list.

### Every endpoint returns `503` "Health check error"

The package could not run, most often because of an invalid custom check registration (reserved/duplicate/invalid name, invalid or colliding custom path, a class in `checks` that does not exist). `php artisan lhe:routes` prints the exact message; the log has `[LHE] Health endpoint error` with the exception.

### My custom check does not show up

- The namespace must match the path (`app/HealthChecks/Foo/BarCheck.php` → `App\HealthChecks\Foo\BarCheck`), the class must not be abstract, and it must implement `HealthCheck` (extend `Check`).
- Files that fail to load (syntax error, missing parent class) are **skipped silently** during discovery. Run `php -l app/HealthChecks/YourCheck.php`.
- `LHE_DISCOVER_ENABLED=false` or a different `LHE_DISCOVER_PATH`/`LHE_DISCOVER_NAMESPACE`.
- Run `php artisan lhe:routes` to see what is registered.

### Results look stale / `"cached": true`

Results are cached for `LHE_CACHE_TTL` seconds (default 10) per URL. Set `LHE_CACHE_TTL=0` to disable, or use `php artisan lhe:run`, which never uses the cache.

### `429 Too Many Requests`

More than `LHE_RATE_LIMIT` requests per minute from one IP. That includes several monitors behind one NAT and all endpoints combined. Reduce polling or raise the limit.

### Markers do not appear

- The route is not in the `web` middleware group (e.g. `api` routes, or a custom group).
- The response is not `text/html`, not `2xx`, is a `StreamedResponse`/`BinaryFileResponse`, or carries `Content-Encoding` (compressed by the app) or `Content-Disposition: attachment`.
- The page has no `<head>` / `</body>` tags.
- `LHE_MARKERS_ENABLED=false`, or the marker env var is empty.
- A full-page cache or CDN serves HTML that was cached before the package was installed. Purge it.
- Fallback: use `@lheHeaderMarker` / `@lheFooterMarker` in your layout.

### Performance without route cache

The package registers its routes after the app has booted. Without `php artisan route:cache`, building the route list resolves the check registry (including discovery of `app/HealthChecks`) on **every request to your app**, not just health requests. Run `php artisan route:cache` in production. Then the registry is only built when a health endpoint is called.

---

## 16. Versioning and changelog

- Releases are created automatically by [semantic-release](https://semantic-release.gitbook.io/) from [Conventional Commits](https://www.conventionalcommits.org/) on `master`. Versions follow [SemVer](https://semver.org/), and [CHANGELOG.md](CHANGELOG.md) is generated from the commit history.
- **API version `v1`:** the URL prefix `/lhe/v1`, the JSON field names and meanings, the HTTP status codes and the error bodies are stable for the lifetime of `v1`. New optional fields or endpoints may be added, so clients should ignore unknown fields. A breaking change to the HTTP contract will ship under a new prefix (`/lhe/v2`) in a new major package version.
- The PHP extension API (`Check`, `TargetedCheck`, `HealthCheck`, `HasTargets`, `HasCustomRoute`, `CheckResult`, `Status`, the events and the facade) follows SemVer: breaking changes only in major versions.
- Security issues: see [SECURITY.md](SECURITY.md). Please do not open public issues for vulnerabilities.

## License

MIT
