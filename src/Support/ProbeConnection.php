<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/**
 * Throwaway copy of an app database connection with the LHE_TIMEOUT connect timeout applied. The probe gets a
 * random-suffixed name (Octane-safe), is purged and removed from config afterwards, and the app's own connection
 * is never reused, reconnected or purged.
 */
final class ProbeConnection
{
    private const PREFIX = 'lhe_probe_';

    /**
     * @template TReturn
     *
     * @param  array<mixed>  $source  the app connection's config
     * @param  Closure(ConnectionInterface, string): TReturn  $callback  receives the connection and its name
     * @return TReturn
     */
    public static function using(string $name, array $source, int $timeout, Closure $callback): mixed
    {
        $probe = self::PREFIX.$name.'_'.Str::random(8);

        self::setConnection($probe, self::config($source, $timeout));

        try {
            return $callback(DB::connection($probe), $probe);
        } finally {
            DB::purge($probe);
            self::setConnection($probe, null);
        }
    }

    /**
     * Copy of a connection config with the driver-specific connect timeout applied.
     *
     * @param  array<mixed>  $config
     * @return array<string, mixed>
     */
    public static function config(array $config, int $timeout): array
    {
        $out = [];
        foreach ($config as $key => $value) {
            $out[(string) $key] = $value;
        }

        switch ($out['driver'] ?? null) {
            case 'mysql':
            case 'mariadb':
            case 'pgsql':
                $options = is_array($out['options'] ?? null) ? $out['options'] : [];
                $options[PDO::ATTR_TIMEOUT] = $timeout;
                $out['options'] = $options;
                break;
            case 'sqlsrv':
                $out['login_timeout'] = $timeout;
                break;
        }

        return $out;
    }

    /**
     * Whole-array access so names containing dots are never treated as config paths.
     *
     * @param  array<string, mixed>|null  $config  null removes the entry
     */
    private static function setConnection(string $name, ?array $config): void
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
}
