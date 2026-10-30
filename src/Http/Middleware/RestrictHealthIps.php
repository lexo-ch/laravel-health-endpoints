<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LEXO\LaravelHealthEndpoints\Http\ErrorResponse;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional IP/CIDR allowlist. Uses $request->ip() only (trusted proxies are the app's responsibility).
 */
final class RestrictHealthIps
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->settings->allowedIps();

        if ($allowed === []) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip === null || $ip === '' || ! IpUtils::checkIp($ip, $allowed)) {
            return ErrorResponse::make(ErrorResponse::FORBIDDEN, 403);
        }

        return $next($request);
    }
}
