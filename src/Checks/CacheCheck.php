<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class CacheCheck extends TargetedCheck
{
    private const KEY_PREFIX = 'lhe:probe:';

    private const TTL_SECONDS = 10;

    public function targets(): array
    {
        $configured = app(Settings::class)->list('cache.stores');

        if ($configured !== []) {
            return $configured;
        }

        $default = config('cache.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }

    public function runTarget(string $target): CheckResult
    {
        $stores = config('cache.stores');

        if (! is_array($stores) || ! is_array($stores[$target] ?? null)) {
            return CheckResult::fail('Store not configured');
        }

        $key = self::KEY_PREFIX.Str::random(16);
        $value = Str::random(32);
        $store = Cache::store($target);

        try {
            $store->put($key, $value, self::TTL_SECONDS);

            if ($store->get($key) !== $value) {
                return CheckResult::fail('Read-back mismatch');
            }

            return CheckResult::ok();
        } finally {
            $store->forget($key);
        }
    }
}
