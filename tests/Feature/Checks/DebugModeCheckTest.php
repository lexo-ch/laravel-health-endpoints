<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Checks\DebugModeCheck;

function lheDebug(bool $debug, string $env, string $forbidden = 'production'): array
{
    config(['app.debug' => $debug, 'laravel-health-endpoints.debug_mode.forbidden_environments' => $forbidden]);
    app()['env'] = $env;
    $r = (new DebugModeCheck)->run();

    return [$r->isOk(), $r->message, $r->meta];
}

it('is named debug-mode', function () {
    expect((new DebugModeCheck)->name())->toBe('debug-mode');
});

it('fails when debug is on in production', function () {
    [$ok, $msg] = lheDebug(true, 'production');
    expect($ok)->toBeFalse()->and($msg)->toBe('APP_DEBUG is enabled in production');
});

it('passes when debug is on in local', function () {
    [$ok, , $meta] = lheDebug(true, 'local');
    expect($ok)->toBeTrue()->and($meta)->toBe(['debug' => true]);
});

it('passes when debug is off in production', function () {
    [$ok, , $meta] = lheDebug(false, 'production');
    expect($ok)->toBeTrue()->and($meta)->toBe(['debug' => false]);
});

it('honours a custom forbidden list', function () {
    [$ok, $msg] = lheDebug(true, 'staging', 'staging,production');
    expect($ok)->toBeFalse()->and($msg)->toBe('APP_DEBUG is enabled in staging');
});

it('never fails with an empty list', function () {
    [$ok] = lheDebug(true, 'production', '');
    expect($ok)->toBeTrue();
});
