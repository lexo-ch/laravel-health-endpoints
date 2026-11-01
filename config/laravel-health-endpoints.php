<?php

declare(strict_types=1);

return [
    'token' => env('LHE_TOKEN', ''),
    'rate_limit' => env('LHE_RATE_LIMIT', 60),              // requests per minute per IP
    'allowed_ips' => env('LHE_ALLOWED_IPS', ''),            // comma-separated IPs/CIDRs, empty = public
    'timeout' => env('LHE_TIMEOUT', 3),
    'cache_ttl' => env('LHE_CACHE_TTL', 10),               // seconds, 0 = off
    'cache_store' => env('LHE_CACHE_STORE', 'file'),
    'heartbeat_store' => env('LHE_HEARTBEAT_STORE'),       // null = app default cache store

    'show_version' => env('LHE_SHOW_VERSION', true),      // ping only: the "version" key of composer.json

    'log' => [
        'channel' => env('LHE_LOG_CHANNEL'),
        'llm_channel' => env('LHE_LOG_LLM_CHANNEL'),
        'llm_priority' => env('LHE_LOG_LLM_PRIORITY'),
        'remind_minutes' => env('LHE_LOG_REMIND_MINUTES', 0),
    ],

    'database' => [
        'enabled' => env('LHE_DATABASE_ENABLED', true),
        'connections' => env('LHE_DB_CONNECTIONS', ''), // comma-separated, empty = default
    ],
    'redis' => [
        'enabled' => env('LHE_REDIS_ENABLED', true),
        'connections' => env('LHE_REDIS_CONNECTIONS', ''), // empty = "default"
    ],
    'cache' => [
        'enabled' => env('LHE_CACHE_ENABLED', true),
        'stores' => env('LHE_CACHE_STORES', ''),           // empty = default store
    ],
    'storage' => [
        'enabled' => env('LHE_STORAGE_ENABLED', true),
        'disks' => env('LHE_STORAGE_DISKS', ''),           // empty = default disk
    ],
    'queue' => [
        'enabled' => env('LHE_QUEUE_ENABLED', true),
        'connections' => env('LHE_QUEUE_CONNECTIONS', ''), // "conn" or "conn:queue", empty = default
        'max_age' => env('LHE_QUEUE_MAX_AGE', 5),          // minutes
    ],
    'scheduler' => [
        'enabled' => env('LHE_SCHEDULER_ENABLED', true),
        'max_age' => env('LHE_SCHEDULER_MAX_AGE', 2),      // minutes
    ],
    'migrations' => [
        'enabled' => env('LHE_MIGRATIONS_ENABLED', true),
    ],
    'debug_mode' => [
        'enabled' => env('LHE_DEBUG_MODE_ENABLED', true),
        'forbidden_environments' => env('LHE_DEBUG_FORBIDDEN_ENVIRONMENTS', 'production'),
    ],

    'markers' => [
        'enabled' => env('LHE_MARKERS_ENABLED', true),
        'header' => env('LHE_MARKER_HEADER', '<template id="lhe-service-up-header">UP</template>'),
        'footer' => env('LHE_MARKER_FOOTER', '<template id="lhe-service-up-footer">UP</template>'),
    ],

    'discover' => [
        'enabled' => env('LHE_DISCOVER_ENABLED', true),
        'path' => env('LHE_DISCOVER_PATH', 'app/HealthChecks'),
        'namespace' => env('LHE_DISCOVER_NAMESPACE', 'App\\HealthChecks'),
    ],

    'checks' => [
        // App\Support\Health\BarCheck::class,
    ],
];
