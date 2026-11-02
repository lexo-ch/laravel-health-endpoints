<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Carbon\CarbonImmutable;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class QueueCheck extends TargetedCheck
{
    public function targets(): array
    {
        $configured = app(Settings::class)->list('queue.connections');

        if ($configured !== []) {
            return $configured;
        }

        $default = config('queue.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }

    /**
     * Splits "conn" / "conn:queue" on the first colon and resolves the queue name.
     *
     * @return array{string, string}
     */
    public static function parseTarget(string $target): array
    {
        [$connection, $queue] = array_pad(explode(':', $target, 2), 2, '');

        if ($queue === '') {
            $connections = config('queue.connections');
            $source = is_array($connections) ? ($connections[$connection] ?? null) : null;
            $configured = is_array($source) ? ($source['queue'] ?? null) : null;
            $queue = is_string($configured) && $configured !== '' ? $configured : 'default';
        }

        return [$connection, $queue];
    }

    /**
     * Driver of a configured connection, or null when the connection is not configured.
     */
    public static function driver(string $connection): ?string
    {
        $connections = config('queue.connections');
        $source = is_array($connections) ? ($connections[$connection] ?? null) : null;

        if (! is_array($source)) {
            return null;
        }

        return is_string($source['driver'] ?? null) ? $source['driver'] : 'unknown';
    }

    public function runTarget(string $target): CheckResult
    {
        [$connection, $queue] = self::parseTarget($target);
        $driver = self::driver($connection);

        if ($driver === null) {
            return CheckResult::fail('Connection not configured');
        }

        if ($driver === 'sync') {
            return CheckResult::ok('sync driver — no worker required');
        }

        if ($driver === 'null') {
            return CheckResult::ok('null driver');
        }

        $last = app(Heartbeats::class)->last(Heartbeats::queueKey($connection.':'.$queue));

        if ($last === null) {
            return CheckResult::fail('No queue heartbeat yet — are the scheduler and a queue worker running?');
        }

        $max = app(Settings::class)->queueMaxAge();
        $meta = ['last_heartbeat' => $last->toIso8601String()];
        $elapsed = (int) $last->diffInSeconds(CarbonImmutable::now(), false);

        if ($elapsed > $max * 60) {
            return CheckResult::fail(
                sprintf('Last queue heartbeat %d min ago (max %d)', intdiv($elapsed, 60), $max),
                $meta,
            );
        }

        return CheckResult::ok(meta: $meta);
    }
}
