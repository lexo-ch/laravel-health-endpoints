<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\ProbeConnection;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use PDO;

final class DatabaseCheck extends TargetedCheck
{
    public function targets(): array
    {
        $configured = app(Settings::class)->list('database.connections');

        if ($configured !== []) {
            return $configured;
        }

        $default = config('database.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }

    public function runTarget(string $target): CheckResult
    {
        $connections = config('database.connections');
        $source = is_array($connections) ? ($connections[$target] ?? null) : null;

        if (! is_array($source)) {
            return CheckResult::fail('Connection not configured');
        }

        $driver = is_string($source['driver'] ?? null) ? $source['driver'] : 'unknown';

        return ProbeConnection::using($target, $source, app(Settings::class)->timeout(), function (ConnectionInterface $connection) use ($driver): CheckResult {
            $connection->select('select 1');

            return CheckResult::ok(meta: [
                'driver' => $driver,
                'version' => $connection instanceof Connection ? $this->version($connection->getPdo()) : null,
            ]);
        });
    }

    private function version(PDO $pdo): ?string
    {
        try {
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            return is_string($version) ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
