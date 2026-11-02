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

    /**
     * The configured token, or null when none is set. Only string values count; see tokenMisconfigured().
     */
    public function token(): ?string
    {
        $value = $this->config->get(self::PREFIX.'token');
        $token = is_string($value) ? trim($value) : '';

        return $token === '' ? null : $token;
    }

    /**
     * A non-empty, non-string token value (array, int, object, ...). Fails closed: the endpoints count as protected
     * and every request is denied, instead of silently treating the value as "no token".
     */
    public function tokenMisconfigured(): bool
    {
        $value = $this->config->get(self::PREFIX.'token');

        return ! is_string($value) && ! empty($value);
    }

    /**
     * Whether ?token= is accepted. Off unless explicitly true: unset, empty and unrecognised values keep it off,
     * so a typo never exposes the token to access logs.
     */
    public function tokenQueryEnabled(): bool
    {
        $value = $this->config->get(self::PREFIX.'token_query');

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_bool($value) && ! is_int($value) && ! is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
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
        return $this->token() !== null || $this->tokenMisconfigured() || $this->allowedIps() !== [];
    }

    /**
     * Whether a boolean `*.enabled` style flag is on. Unset, null, empty and unrecognised values all mean the default
     * (true), so a blank or mistyped env var never silently disables a check.
     */
    public function checkEnabled(string $configKey): bool
    {
        $value = $this->config->get(self::PREFIX.$configKey);

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function rateLimit(): int
    {
        return $this->int('rate_limit', 60, 1);
    }

    /**
     * True only for an explicit "advanced"; unset, empty and unknown values mean "basic" (statuses only).
     */
    public function advancedOutput(): bool
    {
        return strtolower($this->string('output')) === 'advanced';
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
     * Comma-separated string or PHP array as a trimmed, de-duplicated list without empties.
     * Array entries that are not strings are ignored.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->config->get(self::PREFIX.$key);

        $parts = is_array($value)
            ? array_map(trim(...), array_filter($value, is_string(...)))
            : array_map(trim(...), explode(',', $this->string($key)));

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
