<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\ProbeConnection;
use LEXO\LaravelHealthEndpoints\Support\Settings;

/**
 * Reads the migrations table through a throwaway probe connection (LHE_TIMEOUT applied), never the app connection.
 */
final class MigrationsCheck extends Check
{
    private const LISTED = 3;

    public function run(): CheckResult
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $name = $migrator->getConnection() ?? config('database.default');
        $connections = config('database.connections');
        $source = is_string($name) && is_array($connections) ? ($connections[$name] ?? null) : null;

        if (! is_string($name) || ! is_array($source)) {
            return CheckResult::fail('Connection not configured');
        }

        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));

        $ran = ProbeConnection::using($name, $source, app(Settings::class)->timeout(), static function ($connection, string $probe): ?array {
            $repository = new DatabaseMigrationRepository(app('db'), self::table());
            $repository->setSource($probe);

            return $repository->repositoryExists() ? $repository->getRan() : null;
        });

        if ($ran === null) {
            return CheckResult::fail('Migrations table not found');
        }

        $pending = array_values(array_diff($files, $ran));
        $count = count($pending);

        if ($count === 0) {
            return CheckResult::ok(meta: ['pending' => 0]);
        }

        $message = sprintf(
            '%d pending migration(s): %s%s',
            $count,
            implode(', ', array_slice($pending, 0, self::LISTED)),
            $count > self::LISTED ? ', …' : '',
        );

        return CheckResult::fail($message, ['pending' => $count]);
    }

    private static function table(): string
    {
        $migrations = config('database.migrations');
        $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;

        return is_string($table) && $table !== '' ? $table : 'migrations';
    }
}
