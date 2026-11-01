<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;
use LEXO\LaravelHealthEndpoints\Support\Settings;

/**
 * Unique per target for 60 s, so heartbeat jobs do not pile up in the queue while no worker is running.
 * The lock lives in the heartbeat store, which scheduler and workers already share.
 */
final class QueueHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    /** @param  string  $target  Normalized "connection:queue". */
    public function __construct(public readonly string $target) {}

    public function uniqueId(): string
    {
        return $this->target;
    }

    public function uniqueVia(): Repository
    {
        return app(Factory::class)->store(app(Settings::class)->heartbeatStore());
    }

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->touch(Heartbeats::queueKey($this->target));
    }
}
