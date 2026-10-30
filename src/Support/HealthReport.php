<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Carbon\CarbonImmutable;

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
        $checks = [];

        if (isset($data['checks']) && is_array($data['checks'])) {
            foreach ($data['checks'] as $name => $checkData) {
                if (is_array($checkData)) {
                    /** @var array<string, mixed> $checkData */
                    $checks[(string) $name] = CheckReport::fromArray((string) $name, $checkData);
                }
            }
        }

        $duration = $data['duration_ms'] ?? 0;
        $timestamp = $data['timestamp'] ?? null;

        return new self(
            $checks,
            is_string($timestamp) ? CarbonImmutable::parse($timestamp) : CarbonImmutable::now(),
            is_int($duration) || is_float($duration) ? (float) $duration : 0.0,
            true,
        );
    }
}
