<?php

return [
    'ingestion' => [
        'enabled' => env('ANALYTICS_INGESTION_ENABLED', false),
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
