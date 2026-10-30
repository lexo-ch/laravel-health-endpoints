<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Runner;

use Carbon\CarbonImmutable;
use LEXO\LaravelHealthEndpoints\Contracts\HasTargets;
use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use LEXO\LaravelHealthEndpoints\Exceptions\TargetNotFoundException;
use LEXO\LaravelHealthEndpoints\Support\CheckReport;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\HealthReport;
use LEXO\LaravelHealthEndpoints\Support\MessageSanitizer;
use LEXO\LaravelHealthEndpoints\Support\Status;
use Throwable;

final class HealthRunner
{
    public function __construct(private readonly StateTracker $tracker) {}

    /**
     * @throws TargetNotFoundException
     */
    public function runCheck(HealthCheck $check, ?string $target = null): CheckReport
    {
        if ($check instanceof HasTargets) {
            return $this->runTargeted($check, $target);
        }

        if ($target !== null) {
            throw TargetNotFoundException::noTargets($check->name());
        }

        [$result, $ms, $exception] = $this->measure($check->run(...));
        $this->tracker->record($check->name(), null, $result, $ms, $exception);

        return $this->report($check->name(), $result, $ms);
    }

    /**
     * @param  iterable<HealthCheck>  $checks
     */
    public function runAll(iterable $checks): HealthReport
    {
        $timestamp = CarbonImmutable::now();
        $start = hrtime(true);
        $reports = [];

        foreach ($checks as $check) {
            $reports[$check->name()] = $this->runCheck($check);
        }

        return new HealthReport($reports, $timestamp, $this->elapsed($start));
    }

    private function runTargeted(HealthCheck&HasTargets $check, ?string $target): CheckReport
    {
        $name = $check->name();
        $configured = $check->targets();

        if ($target !== null && ! in_array($target, $configured, true)) {
            throw TargetNotFoundException::forTarget($name, $target);
        }

        $selected = $target === null ? $configured : [$target];

        if ($selected === []) {
            $result = CheckResult::fail('No targets configured');
            $this->tracker->record($name, null, $result, 0.0);

            return $this->report($name, $result, 0.0);
        }

        $start = hrtime(true);
        $targets = [];
        $failed = false;

        foreach ($selected as $t) {
            [$result, $ms, $exception] = $this->measure(fn (): CheckResult => $check->runTarget($t));
            $this->tracker->record($name, $t, $result, $ms, $exception);
            $targets[$t] = $this->report($t, $result, $ms);
            $failed = $failed || ! $result->isOk();
        }

        return new CheckReport($name, $failed ? Status::Fail : Status::Ok, $this->elapsed($start), targets: $targets);
    }

    /**
     * @param  callable(): CheckResult  $callback
     * @return array{CheckResult, float, Throwable|null}
     */
    private function measure(callable $callback): array
    {
        $start = hrtime(true);
        $exception = null;

        try {
            $result = $this->sanitize($callback());
        } catch (Throwable $e) {
            $exception = $e;
            $result = CheckResult::fail(MessageSanitizer::fromThrowable($e));
        }

        return [$result, $this->elapsed($start), $exception];
    }

    private function sanitize(CheckResult $result): CheckResult
    {
        $message = $result->message === null ? null : MessageSanitizer::sanitize($result->message);

        if ($message === '' && ! $result->isOk()) {
            $message = 'Check failed';
        }

        return new CheckResult($result->status, $message, $result->meta);
    }

    private function report(string $name, CheckResult $result, float $ms): CheckReport
    {
        return new CheckReport($name, $result->status, $ms, $result->message, $result->meta);
    }

    private function elapsed(int|float $start): float
    {
        return (hrtime(true) - $start) / 1_000_000;
    }
}
