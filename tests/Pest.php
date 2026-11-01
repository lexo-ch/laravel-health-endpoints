<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
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

/**
 * Intercepts the next mysql connection the framework builds: records its final config (after the connector's
 * lazy PDO resolver is set up, before any network I/O) and hands back an in-memory SQLite connection instead.
 * The resolver is removed again after the test.
 */
function lheCaptureMysqlProbeConfig(): object
{
    $captured = new class
    {
        /** @var array<string, mixed> */
        public array $config = [];
    };

    Connection::resolverFor('mysql', function ($pdo, $database, $prefix, array $config) use ($captured) {
        $captured->config = $config;

        return new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', $config);
    });

    test()->beforeApplicationDestroyed(static function (): void {
        (static function (): void {
            unset(static::$resolvers['mysql']);
        })->bindTo(null, Connection::class)();
    });

    return $captured;
}

/**
 * Points the app base path at a fresh temp directory holding the given composer.json (none when null).
 */
function lheUseComposerJson(?string $contents): void
{
    $dir = sys_get_temp_dir().'/lhe-composer-'.bin2hex(random_bytes(6));
    mkdir($dir);

    if ($contents !== null) {
        file_put_contents($dir.'/composer.json', $contents);
    }

    app()->setBasePath($dir);
}
