<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LEXO\LaravelHealthEndpoints\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outermost package middleware: security headers on every response, and a last-resort guard that replaces any
 * framework-rendered server error (which may be a debug page) with the package's JSON error.
 */
final class SecureHealthHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The package itself only ever answers 5xx with 503; anything else came from the exception handler.
        if ($response->getStatusCode() >= 500 && $response->getStatusCode() !== 503) {
            $response = ErrorResponse::make(ErrorResponse::ERROR, 503);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
