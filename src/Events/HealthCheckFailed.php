<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class HealthCheckFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $check,
        public readonly ?string $target,
        public readonly CheckResult $result,
        public readonly float $durationMs,
    ) {}
}
