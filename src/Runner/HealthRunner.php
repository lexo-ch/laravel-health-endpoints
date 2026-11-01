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
use LEXO\LaravelHealthEndpoints\Support\Settings;
use LEXO\LaravelHealthEndpoints\Support\Status;
use Throwable;

final class HealthRunner
{
    public function __construct(
        private readonly StateTracker $tracker,
        private readonly Settings $settings,
    ) {}

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

        try {
            $configured = $check->targets();
        } catch (Throwable $e) {
            $result = CheckResult::fail(MessageSanitizer::fromThrowable($e));
            $this->tracker->record($name, null, $result, 0.0, $e);

            return $this->report($name, $result, 0.0);
        }

        if ($target !== null && ! in_array($target, $configured, true)) {
            throw TargetNotFoundException::forTarget($name, $target);
        }

        $selected = $target === null ? $configured : [$target];

        if ($selected === []) {
            $result = CheckResult::fail('No targets configured');
            $this->tracker->record($name, null, $result, 0.0);

            return $this->report($name, $result, 0.0);
        }

        // The target list is available again: clear a previous check-level failure (recovery log + event once),
        // so that the next check-level failure is logged again.
        $this->tracker->record($name, null, CheckResult::ok(), 0.0);

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
     * Socket timeouts bounded by LHE_TIMEOUT while a check or target runs: stream-based socket I/O (predis, phpredis
     * without own timeouts, file/HTTP streams) and mysqlnd reads on connections opened during the check.
     */
    private const TIMEOUT_INI = ['default_socket_timeout', 'mysqlnd.net_read_timeout'];

    /**
     * Runs one check or target with the socket timeouts above set to LHE_TIMEOUT; previous values are always restored.
     *
     * @param  callable(): CheckResult  $callback
     * @return array{CheckResult, float, Throwable|null}
     */
    private function measure(callable $callback): array
    {
        $start = hrtime(true);
        $exception = null;
        $timeout = (string) $this->settings->timeout();
        $previous = [];

        try {
            foreach (self::TIMEOUT_INI as $key) {
                $value = ini_get($key);

                if ($value !== false && ini_set($key, $timeout) !== false) {
                    $previous[$key] = $value;
                }
            }

            $result = $this->sanitize($callback());
        } catch (Throwable $e) {
            $exception = $e;
            $result = CheckResult::fail(MessageSanitizer::fromThrowable($e));
        } finally {
            foreach ($previous as $key => $value) {
                ini_set($key, $value);
            }
        }

        return [$result, $this->elapsed($start), $exception];
    }

    private function sanitize(CheckResult $result): CheckResult
    {
        $message = $result->message === null ? null : MessageSanitizer::sanitize($result->message);

        if ($message === '') {
            $message = $result->isOk() ? null : 'Check failed';
        }

        /** @var array<string, mixed> $meta */
        $meta = $this->sanitizeValue($result->meta);

        return new CheckResult($result->status, $message, $meta);
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return MessageSanitizer::sanitize($value);
        }

        if (is_array($value)) {
            return array_map($this->sanitizeValue(...), $value);
        }

        return $value;
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
