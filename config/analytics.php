<?php

return [
    'ingestion' => [
        'enabled' => env('ANALYTICS_INGESTION_ENABLED', false),
    ],
    'operations' => [
        'enabled' => env('ANALYTICS_OPERATIONS_ENABLED', false),
        'rollups_enabled' => env('ANALYTICS_ROLLUPS_ENABLED', false),
        'partitions_enabled' => env('ANALYTICS_PARTITIONS_ENABLED', false),
        'max_backfill_days' => env('ANALYTICS_MAX_BACKFILL_DAYS', 31),
        'partition_months_ahead' => env('ANALYTICS_PARTITION_MONTHS_AHEAD', 3),
        'partition_months_behind' => env('ANALYTICS_PARTITION_MONTHS_BEHIND', 1),
        'statement_timeout_ms' => env('ANALYTICS_STATEMENT_TIMEOUT_MS', 30000),
        'lock_timeout_ms' => env('ANALYTICS_LOCK_TIMEOUT_MS', 5000),
    ],
    'consent' => [
        'version' => env('ANALYTICS_CONSENT_VERSION', 1),
    ],
    'session' => [
        'ttl_minutes' => env('ANALYTICS_SESSION_TTL_MINUTES', 30),
        'max_hours' => env('ANALYTICS_SESSION_MAX_HOURS', 24),
    ],
    'rate_limit' => [
        'per_minute' => env('ANALYTICS_RATE_LIMIT_PER_MINUTE', 30),
    ],
    'properties' => [
        'max_bytes' => env('ANALYTICS_PROPERTIES_MAX_BYTES', 4096),
    ],
    'ip_hash' => [
        'key' => env('ANALYTICS_IP_HASH_KEY'),
        'key_version' => env('ANALYTICS_IP_HASH_KEY_VERSION', 1),
    ],
];
