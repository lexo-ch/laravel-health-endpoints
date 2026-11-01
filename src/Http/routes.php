<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use LEXO\LaravelHealthEndpoints\Http\Controllers\HealthController;
use LEXO\LaravelHealthEndpoints\Http\Middleware\AuthorizeHealthToken;
use LEXO\LaravelHealthEndpoints\Http\Middleware\RestrictHealthIps;
use LEXO\LaravelHealthEndpoints\Http\Middleware\SecureHealthHeaders;
use LEXO\LaravelHealthEndpoints\Http\Middleware\ThrottleHealthRequests;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;

/*
 * Package routes. Deliberately outside the app's `web`/`api` groups (no session, cookies or CSRF).
 * Middleware order is significant: headers → rate limit → IP allowlist → token → routing/404.
 * No closures, so `route:cache` works.
 */

try {
    // Resolve on a clone so the shared registry stays lazy (config changes before the first real use still apply).
    $lheCustomRoutes = (clone app(HealthCheckRegistry::class))->customRoutes();
} catch (Throwable) {
    // An invalid check must not break the application's routing; the endpoints answer 503 and log it.
    $lheCustomRoutes = [];
}

Route::prefix('lhe/v1')
    ->name('lhe.')
    ->middleware([
        SecureHealthHeaders::class,
        ThrottleHealthRequests::class,
        RestrictHealthIps::class,
        AuthorizeHealthToken::class,
    ])
    ->group(static function () use ($lheCustomRoutes): void {
        Route::match(['GET', 'HEAD'], '/', [HealthController::class, 'all'])->name('all');
        Route::match(['GET', 'HEAD'], 'ping', [HealthController::class, 'ping'])->name('ping');

        foreach ($lheCustomRoutes as $path => $name) {
            Route::match(['GET', 'HEAD'], $path, [HealthController::class, 'custom'])
                ->defaults('check', $name)
                ->name('custom.'.$name);
        }

        Route::match(['GET', 'HEAD'], '{check}/{target?}', [HealthController::class, 'check'])
            ->where(['check' => '[a-z][a-z0-9-]*', 'target' => '[A-Za-z0-9_.:-]{1,128}'])
            ->name('check');

        // Router::$verbs plus uncommon/WebDAV verbs, so they also get the package's JSON 405 after auth.
        Route::match([...Router::$verbs, ...HealthController::EXTRA_METHODS], '{any?}', [HealthController::class, 'fallback'])
            ->where('any', '.*')
            ->name('fallback');
    });

unset($lheCustomRoutes);
