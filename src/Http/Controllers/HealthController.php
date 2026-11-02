<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http\Controllers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Contracts\HasTargets;
use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use LEXO\LaravelHealthEndpoints\Exceptions\TargetNotFoundException;
use LEXO\LaravelHealthEndpoints\Http\ErrorResponse;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Support\HealthReport;
use LEXO\LaravelHealthEndpoints\Support\Json;
use LEXO\LaravelHealthEndpoints\Support\ResultCache;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Throwable;

/**
 * Runs only after rate limit, IP allowlist and token middleware. Check names and targets from the URL are only
 * compared against the registry/configured lists; cache scopes are built from resolved values only.
 * Services are resolved inside the guard so that no failure can reach Laravel's exception page.
 */
final class HealthController
{
    /**
     * Methods beyond Router::$verbs routed to fallback(), so they get the package's JSON 405 (after auth, with security
     * headers) instead of the framework's. Methods not listed here still get the framework's 405.
     *
     * @var list<string>
     */
    public const EXTRA_METHODS = [
        'TRACE', 'CONNECT', 'QUERY', 'PURGE', 'LINK', 'UNLINK',
        'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'SEARCH', 'REPORT',
    ];

    private const ERROR_LOG_SECONDS = 60;

    private const OUTPUT_HEADER = 'X-LHE-Output';

    private const OUTPUT_QUERY = 'output';

    public function all(Request $request): JsonResponse
    {
        return $this->guard($request, function (): JsonResponse {
            $registry = app(HealthCheckRegistry::class);

            return $this->respond(app(ResultCache::class)->remember(
                'all',
                static fn (): HealthReport => app(HealthRunner::class)->runAll($registry->all()),
            ));
        });
    }

    public function ping(Request $request): JsonResponse
    {
        return $this->guard($request, static fn (): JsonResponse => Json::response(['status' => 'ok']));
    }

    public function check(Request $request, string $check, ?string $target = null): JsonResponse
    {
        return $this->guard($request, function () use ($check, $target): JsonResponse {
            $found = app(HealthCheckRegistry::class)->find($check);

            if ($found === null) {
                return ErrorResponse::make(ErrorResponse::NOT_FOUND, 404);
            }

            if ($target === null) {
                return $this->runSingle($found, null);
            }

            if (! $found instanceof HasTargets) {
                return ErrorResponse::make(ErrorResponse::NOT_FOUND, 404);
            }

            try {
                $configured = $found->targets();
            } catch (Throwable) {
                // Target list unavailable: run uncached (raw input never reaches a cache key); the runner
                // reports the check as failed and records the state transition.
                return $this->respond($this->singleReport($found, $target));
            }

            $index = array_search($target, $configured, true);

            if ($index === false) {
                return ErrorResponse::make(ErrorResponse::NOT_FOUND, 404);
            }

            return $this->runSingle($found, $configured[$index]);
        });
    }

    public function custom(Request $request, string $check): JsonResponse
    {
        return $this->guard($request, function () use ($check): JsonResponse {
            $found = app(HealthCheckRegistry::class)->find($check);

            return $found === null
                ? ErrorResponse::make(ErrorResponse::NOT_FOUND, 404)
                : $this->runSingle($found, null);
        });
    }

    public function fallback(Request $request): JsonResponse
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return ErrorResponse::make(ErrorResponse::METHOD_NOT_ALLOWED, 405, ['Allow' => 'GET, HEAD']);
        }

        return ErrorResponse::make(ErrorResponse::NOT_FOUND, 404);
    }

    private function runSingle(HealthCheck $check, ?string $target): JsonResponse
    {
        $name = $check->name();
        $scope = $target === null ? 'check:'.$name : 'check:'.$name.':'.$target;

        return $this->respond(app(ResultCache::class)->remember(
            $scope,
            fn (): HealthReport => $this->singleReport($check, $target),
        ));
    }

    /**
     * @throws TargetNotFoundException
     */
    private function singleReport(HealthCheck $check, ?string $target): HealthReport
    {
        $timestamp = CarbonImmutable::now();
        $start = hrtime(true);
        $report = app(HealthRunner::class)->runCheck($check, $target);

        return new HealthReport([$check->name() => $report], $timestamp, (hrtime(true) - $start) / 1_000_000);
    }

    private function respond(HealthReport $report): JsonResponse
    {
        return Json::response($report->toJsonArray($this->advanced()), $report->httpStatus());
    }

    /**
     * Advanced when configured, or when a token is configured (so this request was authenticated by it) and the
     * request asks for it via the X-LHE-Output header or ?output=. Requests can only upgrade, never downgrade, and
     * without a token (public or IP allowlist only) the request has no say.
     */
    private function advanced(): bool
    {
        $settings = app(Settings::class);

        if ($settings->advancedOutput()) {
            return true;
        }

        if ($settings->token() === null) {
            return false;
        }

        $request = request();
        $requested = $request->headers->get(self::OUTPUT_HEADER) ?? $request->query(self::OUTPUT_QUERY);

        return is_string($requested) && strtolower(trim($requested)) === 'advanced';
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function guard(Request $request, Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (TargetNotFoundException) {
            return ErrorResponse::make(ErrorResponse::NOT_FOUND, 404);
        } catch (Throwable $e) {
            $this->logError($request, $e);

            return ErrorResponse::make(ErrorResponse::ERROR, 503);
        }
    }

    /**
     * Logs at most once per ERROR_LOG_SECONDS per exception class (flag in the package store), so a broken check
     * polled by several monitors cannot flood the log. If the store itself is down, it logs every time.
     */
    private function logError(Request $request, Throwable $e): void
    {
        try {
            $store = Cache::store(app(Settings::class)->cacheStore());

            if (! $store->add('lhe:endpoint-error:'.sha1($e::class), true, self::ERROR_LOG_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // Throttle store unavailable: log anyway.
        }

        try {
            Log::error('[LHE] Health endpoint error', [
                'exception' => $e,
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
        } catch (Throwable) {
            // Logging must not break the error response.
        }
    }
}
