<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class HealthReport
{
    /**
     * @param  array<string, CheckReport>  $checks
     */
    public function __construct(
        public array $checks,
        public CarbonImmutable $timestamp,
        public float $durationMs,
        public bool $cached = false,
    ) {}

    public function status(): Status
    {
        foreach ($this->checks as $check) {
            if ($check->status === Status::Fail) {
                return Status::Fail;
            }
        }

        return Status::Ok;
    }

    public function httpStatus(): int
    {
        return $this->status() === Status::Ok ? 200 : 503;
    }

    /**
     * @param  array<string, mixed>|null  $app  only rendered in detailed mode
     * @return array<string, mixed>
     */
    public function toArray(bool $detailed, ?array $app = null): array
    {
        $out = [
            'status' => $this->status()->value,
            'timestamp' => $this->timestamp->toIso8601String(),
        ];

        if ($detailed) {
            $out['duration_ms'] = (int) round($this->durationMs);
            $out['cached'] = $this->cached;

            if ($app !== null) {
                $out['app'] = $app;
            }
        }

        $out['checks'] = array_map(
            static fn (CheckReport $c): array => $c->toArray($detailed),
            $this->checks,
        );

        return $out;
    }

    /**
     * Like toArray(), but the `checks` and `targets` maps are objects, so they always encode as JSON objects:
     * `{}` when empty, and numeric names ("0", "1") stay object keys instead of turning the map into a list.
     *
     * @param  array<string, mixed>|null  $app  only rendered in detailed mode
     * @return array<string, mixed>
     */
    public function toJsonArray(bool $detailed, ?array $app = null): array
    {
        $out = $this->toArray($detailed, $app);

        $out['checks'] = (object) array_map(
            static fn (CheckReport $c): array => $c->toJsonArray($detailed),
            $this->checks,
        );

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'duration_ms' => $this->durationMs,
            'checks' => array_map(
                static fn (CheckReport $c): array => $c->toCacheArray(),
                $this->checks,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromCacheArray(array $data): self
    {
        $timestamp = $data['timestamp'] ?? null;
        $duration = $data['duration_ms'] ?? null;

        if (! is_string($timestamp) || ! is_array($data['checks'] ?? null) || (! is_int($duration) && ! is_float($duration))) {
            throw new InvalidArgumentException('Corrupt health report cache data.');
        }

        try {
            $parsed = CarbonImmutable::parse($timestamp);
        } catch (Throwable) {
            throw new InvalidArgumentException('Corrupt health report cache timestamp.');
        }

        $checks = [];

        foreach ($data['checks'] as $name => $checkData) {
            if (! is_array($checkData)) {
                throw new InvalidArgumentException('Corrupt health report cache check entry.');
            }

            /** @var array<string, mixed> $checkData */
            $checks[(string) $name] = CheckReport::fromArray((string) $name, $checkData);
        }

        return new self($checks, $parsed, (float) $duration, true);
    }
}
