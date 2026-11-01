<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use LEXO\LaravelHealthEndpoints\Http\ErrorResponse;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Per-IP rate limit, applied before auth to blunt token brute force. Counters live in the package's own cache store
 * (LHE_CACHE_STORE, `file` by default), never the app's default/limiter store, so `ping` stays dependency-free.
 * Fails open if that store is down, so a cache outage cannot take the health endpoints with it (IP allowlist and
 * token still apply).
 */
final class ThrottleHealthRequests
{
    private const DECAY_SECONDS = 60;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'lhe:'.sha1((string) $request->ip());

        try {
            $limiter = new RateLimiter(Cache::store($this->settings->cacheStore()));
            $allowed = $limiter->attempt($key, $this->settings->rateLimit(), static fn (): bool => true, self::DECAY_SECONDS);
            $retryAfter = $allowed ? 0 : $limiter->availableIn($key);
        } catch (Throwable) {
            $allowed = true;
            $retryAfter = 0;
        }

        if ($allowed === false) {
            return ErrorResponse::make(ErrorResponse::TOO_MANY_REQUESTS, 429, ['Retry-After' => (string) max(1, $retryAfter)]);
        }

        return $next($request);
    }
}
