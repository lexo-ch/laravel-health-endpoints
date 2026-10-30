<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Support\Facades\DB;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use PDO;

final class DatabaseCheck extends TargetedCheck
{
    private const PROBE_PREFIX = 'lhe_probe_';

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

        $timeout = app(Settings::class)->timeout();
        $probe = self::PROBE_PREFIX.$target;
        $driver = is_string($source['driver'] ?? null) ? $source['driver'] : 'unknown';

        $this->setConnection($probe, $this->withTimeout($this->stringKeyed($source), $driver, $timeout));

        try {
            $connection = DB::connection($probe);
            $connection->select('select 1');

            return CheckResult::ok(meta: [
                'driver' => $driver,
                'version' => $this->version($connection->getPdo()),
            ]);
        } finally {
            DB::purge($probe);
            $this->setConnection($probe, null);
        }
    }

    /**
     * @param  array<mixed>  $config
     * @return array<string, mixed>
     */
    private function stringKeyed(array $config): array
    {
        $out = [];
        foreach ($config as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withTimeout(array $config, string $driver, int $timeout): array
    {
        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $options = is_array($config['options'] ?? null) ? $config['options'] : [];
                $options[PDO::ATTR_TIMEOUT] = $timeout;
                $config['options'] = $options;
                break;
            case 'pgsql':
                $config['connect_timeout'] = $timeout;
                break;
            case 'sqlsrv':
                $config['login_timeout'] = $timeout;
                break;
        }

        return $config;
    }

    /**
     * Whole-array access so names containing dots are never treated as config paths.
     *
     * @param  array<string, mixed>|null  $config  null removes the entry
     */
    private function setConnection(string $name, ?array $config): void
    {
        $all = config('database.connections');
        $all = is_array($all) ? $all : [];

        if ($config === null) {
            unset($all[$name]);
        } else {
            $all[$name] = $config;
        }

        config()->set('database.connections', $all);
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
