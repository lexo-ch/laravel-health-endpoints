<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

final readonly class CheckResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public Status $status,
        public ?string $message = null,
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(?string $message = null, array $meta = []): self
    {
        return new self(Status::Ok, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fail(string $message, array $meta = []): self
    {
        return new self(Status::Fail, $message, $meta);
    }

    public function isOk(): bool
    {
        return $this->status === Status::Ok;
    }
}
