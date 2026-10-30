<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;

final class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /** @param  string  $target  Normalized "connection:queue". */
    public function __construct(public readonly string $target) {}

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->touch(Heartbeats::queueKey($this->target));
    }
}
