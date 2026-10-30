<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

final readonly class CheckReport
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, CheckReport>|null  $targets
     */
    public function __construct(
        public string $name,
        public Status $status,
        public float $durationMs,
        public ?string $message = null,
        public array $meta = [],
        public ?array $targets = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $detailed): array
    {
        return $detailed ? $this->detailedArray(true) : ['status' => $this->status->value];
    }

    /**
     * Lossless representation (unrounded durations) for the result cache.
     *
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return $this->detailedArray(false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $targets = null;

        if (isset($data['targets']) && is_array($data['targets'])) {
            $targets = [];
            foreach ($data['targets'] as $targetName => $targetData) {
                if (is_array($targetData)) {
                    /** @var array<string, mixed> $targetData */
                    $targets[(string) $targetName] = self::fromArray((string) $targetName, $targetData);
                }
            }
        }

        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        /** @var array<string, mixed> $meta */
        $duration = $data['duration_ms'] ?? 0;

        return new self(
            $name,
            Status::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '') ?? Status::Fail,
            is_int($duration) || is_float($duration) ? (float) $duration : 0.0,
            isset($data['message']) && is_string($data['message']) ? $data['message'] : null,
            $meta,
            $targets,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function detailedArray(bool $round): array
    {
        $out = [
            'status' => $this->status->value,
            'duration_ms' => $round ? (int) round($this->durationMs) : $this->durationMs,
        ];

        if ($this->message !== null) {
            $out['message'] = $this->message;
        }

        if ($this->meta !== []) {
            $out['meta'] = $this->meta;
        }

        if ($this->targets !== null) {
            $out['targets'] = array_map(
                fn (self $t): array => $t->detailedArray($round),
                $this->targets,
            );
        }

        return $out;
    }
}
