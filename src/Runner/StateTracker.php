<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Runner;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckFailed;
use LEXO\LaravelHealthEndpoints\Events\HealthCheckRecovered;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Throwable;

/**
 * Tracks ok/fail per check+target and logs / dispatches events on transitions only.
 * Never throws: store, logging and listener failures are swallowed.
 */
final class StateTracker
{
    public function __construct(private readonly Settings $settings) {}

    public function record(string $check, ?string $target, CheckResult $result, float $durationMs, ?Throwable $exception = null): void
    {
        try {
            $this->handle($check, $target, $result, $durationMs, $exception);
        } catch (Throwable) {
            // monitoring must never break the health endpoint
        }
    }

    private function handle(string $check, ?string $target, CheckResult $result, float $durationMs, ?Throwable $exception): void
    {
        $key = 'lhe:state:'.sha1($check.'|'.($target ?? ''));
        $now = Carbon::now()->getTimestamp();

        $previous = $this->read($key);
        $wasFailing = $previous !== null && $previous['status'] === 'fail';

        if ($result->isOk()) {
            if ($wasFailing) {
                $this->write($key, ['status' => 'ok', 'last_logged' => $now]);
                $this->log('info', $this->label($check, $target).' check recovered', $this->context($check, $target, $result, $durationMs, null, true));
                $this->dispatch(new HealthCheckRecovered($check, $target, $result, $durationMs));
            }

            return;
        }

        $remind = $this->settings->logRemindMinutes();

        if (! $wasFailing) {
            $this->write($key, ['status' => 'fail', 'last_logged' => $now]);
            $this->logFailure($check, $target, $result, $durationMs, $exception);
            $this->dispatch(new HealthCheckFailed($check, $target, $result, $durationMs));

            return;
        }

        if ($remind > 0 && $now - $previous['last_logged'] >= $remind * 60) {
            $this->write($key, ['status' => 'fail', 'last_logged' => $now]);
            $this->logFailure($check, $target, $result, $durationMs, $exception);
        }
    }

    private function logFailure(string $check, ?string $target, CheckResult $result, float $durationMs, ?Throwable $exception): void
    {
        $this->log(
            'error',
            $this->label($check, $target).' check failed: '.($result->message ?? ''),
            $this->context($check, $target, $result, $durationMs, $exception, false),
        );
    }

    private function label(string $check, ?string $target): string
    {
        return '[LHE] '.$check.($target === null ? '' : ':'.$target);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $check, ?string $target, CheckResult $result, float $durationMs, ?Throwable $exception, bool $recovery): array
    {
        $context = [
            'check' => $check,
            'target' => $target,
            'message' => $result->message,
            'meta' => $result->meta,
            'duration_ms' => round($durationMs, 2),
        ];

        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        $context['llm'] = array_filter(
            $recovery
                ? ['alert' => true, 'channel' => $this->settings->logLlmChannel()]
                : ['channel' => $this->settings->logLlmChannel(), 'priority' => $this->settings->logLlmPriority()],
            static fn (mixed $v): bool => $v !== null,
        );

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $channel = $this->settings->logChannel();
            $logger = $channel === null ? Log::getFacadeRoot() : Log::channel($channel);
            $logger->{$level}($message, $context);
        } catch (Throwable) {
            // a broken log channel must not affect the response
        }
    }

    private function dispatch(object $event): void
    {
        try {
            Event::dispatch($event);
        } catch (Throwable) {
            // a failing listener must not affect the response
        }
    }

    /**
     * @return array{status: string, last_logged: int}|null
     */
    private function read(string $key): ?array
    {
        try {
            $value = Cache::store($this->settings->cacheStore())->get($key);
        } catch (Throwable) {
            return null;
        }

        if (is_array($value) && is_string($value['status'] ?? null) && is_int($value['last_logged'] ?? null)) {
            return ['status' => $value['status'], 'last_logged' => $value['last_logged']];
        }

        return null;
    }

    /**
     * @param  array{status: string, last_logged: int}  $state
     */
    private function write(string $key, array $state): void
    {
        try {
            Cache::store($this->settings->cacheStore())->forever($key, $state);
        } catch (Throwable) {
            // state store unavailable: degrade silently
        }
    }
}
