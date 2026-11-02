<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Timestamp-only heartbeats in a shared cache store. Failures never throw.
 */
final class Heartbeats
{
    public const SCHEDULER_KEY = 'lhe:heartbeat:scheduler';

    private const QUEUE_KEY_PREFIX = 'lhe:heartbeat:queue:';

    public function __construct(
        private readonly Factory $cache,
        private readonly Settings $settings,
    ) {}

    public static function queueKey(string $target): string
    {
        return self::QUEUE_KEY_PREFIX.sha1($target);
    }

    /**
     * Static entry point for the scheduled callback (keeps the schedule serializable and listable).
     */
    public static function touchScheduler(): void
    {
        app(self::class)->touch(self::SCHEDULER_KEY);
    }

    public function touch(string $key): void
    {
        try {
            $this->store()->forever($key, CarbonImmutable::now()->getTimestamp());
        } catch (Throwable) {
            // A failing heartbeat store surfaces as a missing heartbeat in the check.
        }
    }

    public function last(string $key): ?CarbonImmutable
    {
        try {
            $value = $this->store()->get($key);
        } catch (Throwable) {
            return null;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) ? CarbonImmutable::createFromTimestamp($value) : null;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->settings->heartbeatStore());
    }
}
