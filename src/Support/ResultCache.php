<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ResultCache
{
    private const PREFIX = 'lhe:result:';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(): HealthReport  $run
     */
    public function remember(string $scope, Closure $run): HealthReport
    {
        $ttl = $this->settings->cacheTtl();

        if ($ttl <= 0) {
            return $run();
        }

        $key = self::PREFIX.sha1($scope);

        try {
            $cached = Cache::store($this->settings->cacheStore())->get($key);

            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return HealthReport::fromCacheArray($cached);
            }
        } catch (Throwable) {
            // Unreadable store or corrupt entry: treat as a miss.
        }

        $report = $run();

        try {
            Cache::store($this->settings->cacheStore())->put($key, $report->toCacheArray(), $ttl);
        } catch (Throwable) {
            // Cache failures must never break the endpoint.
        }

        return $report;
    }
}
