<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use LEXO\LaravelHealthEndpoints\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Re-registers the package routes so checks registered after boot (in a test) get their custom routes.
 */
function lheReloadRoutes(): void
{
    Route::setRoutes(new RouteCollection);

    require dirname(__DIR__).'/src/Http/routes.php';

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}
