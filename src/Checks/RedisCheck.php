<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Throwable;

final class RedisCheck extends TargetedCheck
{
    private const RESERVED = ['client', 'options', 'clusters'];

    public function targets(): array
    {
        $configured = app(Settings::class)->list('redis.connections');

        return $configured !== [] ? $configured : ['default'];
    }

    public function runTarget(string $target): CheckResult
    {
        $redis = config('database.redis');
        $redis = is_array($redis) ? $redis : [];
        $source = in_array($target, self::RESERVED, true) ? null : ($redis[$target] ?? null);

        if (! is_array($source)) {
            return CheckResult::fail('Connection not configured');
        }

        $timeout = app(Settings::class)->timeout();

        $client = is_string($redis['client'] ?? null) ? $redis['client'] : 'phpredis';
        $manager = new RedisManager(app(), $client, [
            'options' => is_array($redis['options'] ?? null) ? $redis['options'] : [],
            $target => $this->probeConfig($source, $timeout),
        ]);

        $connection = null;

        try {
            $connection = $manager->connection($target);
            $connection->command('ping');

            return CheckResult::ok(meta: ['client' => $client, 'version' => $this->version($connection)]);
        } finally {
            try {
                if ($connection !== null) {
                    $this->close($connection);
                }
            } catch (Throwable) {
                // nothing left to release
            }
            $manager->purge($target);
        }
    }

    /**
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    public function probeConfig(array $config, int $timeout): array
    {
        $config['timeout'] = $timeout;
        $config['read_timeout'] = $timeout;
        $config['read_write_timeout'] = $timeout;

        return $config;
    }

    private function close(Connection $connection): void
    {
        $client = $connection->client();

        foreach (['disconnect', 'close'] as $method) {
            if (is_object($client) && method_exists($client, $method)) {
                $client->{$method}();

                return;
            }
        }
    }

    private function version(Connection $connection): ?string
    {
        try {
            $info = $connection->command('info', ['server']);
            $info = is_array($info) ? $info : [];
            $info = is_array($info['Server'] ?? null) ? $info['Server'] : $info;
            $version = $info['redis_version'] ?? null;

            return is_string($version) ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }
}
