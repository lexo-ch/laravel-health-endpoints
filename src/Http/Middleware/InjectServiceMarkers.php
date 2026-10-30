<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class InjectServiceMarkers
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        /** @var SymfonyResponse $response */
        $response = $next($request);

        if (! $this->enabled() || ! $response instanceof Response || ! $this->eligible($response)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return $response;
        }

        $original = $content;

        $header = $this->marker('header');
        if ($header !== '' && ! str_contains($content, $header)
            && preg_match('/<head\b[^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);
            $content = substr_replace($content, $header, $at, 0);
        }

        $footer = $this->marker('footer');
        if ($footer !== '' && ! str_contains($content, $footer)) {
            $at = strripos($content, '</body>');

            if ($at !== false) {
                $content = substr_replace($content, $footer, $at, 0);
            }
        }

        if ($content === $original) {
            return $response;
        }

        $hadLength = $response->headers->has('Content-Length');
        $response->setContent($content);

        if ($hadLength) {
            $response->headers->set('Content-Length', (string) strlen($content));
        }

        return $response;
    }

    private function enabled(): bool
    {
        $value = config('laravel-health-endpoints.markers.enabled', true);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function marker(string $key): string
    {
        $value = config('laravel-health-endpoints.markers.'.$key);

        return is_string($value) ? $value : '';
    }

    private function eligible(Response $response): bool
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status > 299 || $status === 204) {
            return false;
        }

        if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        return ! str_contains(strtolower((string) $response->headers->get('Content-Disposition', '')), 'attachment');
    }
}
