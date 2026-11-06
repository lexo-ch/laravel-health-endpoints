<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Carbon\CarbonImmutable;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class SchedulerCheck extends Check
{
    public function run(): CheckResult
    {
        $last = app(Heartbeats::class)->last(Heartbeats::SCHEDULER_KEY);

        if ($last === null) {
            return CheckResult::fail('No scheduler heartbeat yet — is schedule:run / schedule:work running?');
        }

        $max = app(Settings::class)->schedulerMaxAge();
        $meta = ['last_heartbeat' => $last->toIso8601String()];
        $elapsed = CarbonImmutable::now()->getTimestamp() - $last->getTimestamp();

        if ($elapsed < -Heartbeats::FUTURE_TOLERANCE_SECONDS) {
            return CheckResult::fail('Scheduler heartbeat is in the future — check server clocks', $meta);
        }

        if ($elapsed > $max * 60) {
            return CheckResult::fail(
                sprintf('Last scheduler heartbeat %d min ago (max %d)', intdiv($elapsed, 60), $max),
                $meta,
            );
        }

        return CheckResult::ok(meta: $meta);
    }
}
