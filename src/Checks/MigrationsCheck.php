<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Database\Migrations\Migrator;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class MigrationsCheck extends Check
{
    private const LISTED = 3;

    public function run(): CheckResult
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return CheckResult::fail('Migrations table not found');
        }

        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
        $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));
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
}
