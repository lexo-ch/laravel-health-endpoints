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
 * Exception: an in-memory SQLite database exists only on the app's own connection (a probe would open a new, empty
 * one), so it is read there; it cannot hang.
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

        $ran = self::inMemory($source)
            ? self::ran($name)
            : ProbeConnection::using($name, $source, app(Settings::class)->timeout(), static fn ($connection, string $probe): ?array => self::ran($probe));

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

    /**
     * Names of the migrations that ran, or null when the migrations table does not exist.
     *
     * @return array<string>|null
     */
    private static function ran(string $connection): ?array
    {
        $repository = new DatabaseMigrationRepository(app('db'), self::table());
        $repository->setSource($connection);

        return $repository->repositoryExists() ? $repository->getRan() : null;
    }

    /**
     * Same detection as Laravel's SQLiteConnector.
     *
     * @param  array<mixed>  $source
     */
    private static function inMemory(array $source): bool
    {
        $database = $source['database'] ?? null;

        return ($source['driver'] ?? null) === 'sqlite' && is_string($database)
            && ($database === ':memory:' || str_contains($database, '?mode=memory') || str_contains($database, '&mode=memory'));
    }

    private static function table(): string
    {
        $migrations = config('database.migrations');
        $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;

        return is_string($table) && $table !== '' ? $table : 'migrations';
    }
}
