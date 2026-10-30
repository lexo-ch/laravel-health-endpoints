<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed, forgiving access to package config. Reads config on every call.
 */
final class Settings
{
    private const PREFIX = 'laravel-health-endpoints.';

    public function __construct(private readonly Repository $config) {}

    public function token(): ?string
    {
        $token = $this->string('token');

        return $token === '' ? null : $token;
    }

    /**
     * @return list<string>
     */
    public function allowedIps(): array
    {
        return $this->list('allowed_ips');
    }

    public function isProtected(): bool
    {
        return $this->token() !== null || $this->allowedIps() !== [];
    }

    public function detailed(): bool
    {
        return match (strtolower($this->string('details'))) {
            'always' => true,
            'never' => false,
            default => $this->isProtected(),
        };
    }

    public function rateLimit(): int
    {
        return $this->int('rate_limit', 60, 1);
    }

    public function appInfoEnabled(): bool
    {
        $value = $this->config->get(self::PREFIX.'app_info.enabled');

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function appVersion(): ?string
    {
        return $this->nullableString('app_info.version');
    }

    public function timeout(): int
    {
        return $this->int('timeout', 3, 1);
    }

    public function cacheTtl(): int
    {
        return $this->int('cache_ttl', 10);
    }

    public function cacheStore(): string
    {
        $store = $this->string('cache_store');

        return $store === '' ? 'file' : $store;
    }

    public function heartbeatStore(): ?string
    {
        $store = $this->string('heartbeat_store');

        return $store === '' ? null : $store;
    }

    public function queueMaxAge(): int
    {
        return $this->int('queue.max_age', 5, 1);
    }

    public function schedulerMaxAge(): int
    {
        return $this->int('scheduler.max_age', 2, 1);
    }

    public function logChannel(): ?string
    {
        return $this->nullableString('log.channel');
    }

    public function logLlmChannel(): ?string
    {
        return $this->nullableString('log.llm_channel');
    }

    public function logLlmPriority(): ?string
    {
        return $this->nullableString('log.llm_priority');
    }

    public function logRemindMinutes(): int
    {
        return $this->int('log.remind_minutes', 0);
    }

    /**
     * Comma-separated config value as a trimmed, de-duplicated list without empties.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $parts = array_map(trim(...), explode(',', $this->string($key)));

        return array_values(array_unique(array_filter($parts, static fn (string $v): bool => $v !== '')));
    }

    public function int(string $key, int $default, int $min = 0): int
    {
        $value = $this->config->get(self::PREFIX.$key);

        if (is_string($value)) {
            $value = trim($value);
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            $int = (int) $value;

            return $int >= $min ? $int : $default;
        }

        return $default;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    private function string(string $key): string
    {
        $value = $this->config->get(self::PREFIX.$key);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
