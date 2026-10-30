<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Http\ErrorResponse;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Optional shared token (Bearer header preferred, ?token= accepted). Non-string or empty values count as missing;
 * a non-empty Bearer header always wins over the query. The token is never logged or echoed.
 */
final class AuthorizeHealthToken
{
    private const WARNING_KEY = 'lhe:public-warning';

    private const WARNING_SECONDS = 86400;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->settings->token();

        if ($token === null) {
            $this->warnIfPublic();

            return $next($request);
        }

        $provided = $this->providedToken($request);

        if ($provided === null || ! hash_equals($token, $provided)) {
            return ErrorResponse::make(ErrorResponse::UNAUTHORIZED, 401);
        }

        return $next($request);
    }

    private function providedToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $query = $request->query('token');

        return is_string($query) && $query !== '' ? $query : null;
    }

    /**
     * One warning per 24 h when production endpoints have neither token nor IP allowlist.
     */
    private function warnIfPublic(): void
    {
        if (! app()->isProduction() || $this->settings->isProtected()) {
            return;
        }

        try {
            if (Cache::store($this->settings->cacheStore())->add(self::WARNING_KEY, true, self::WARNING_SECONDS)) {
                Log::warning('[LHE] Health endpoints are public; responses are minimal. Set LHE_TOKEN or LHE_ALLOWED_IPS.');
            }
        } catch (Throwable) {
            // A missing or broken store must never break the endpoint.
        }
    }
}
