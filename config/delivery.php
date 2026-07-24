<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Secure delivery pipeline (P4-C0, D-035)
    |--------------------------------------------------------------------------
    |
    | DISABLED by default. While disabled, OrderPaid triggers no delivery job and
    | no download grant is ever issued. It is enabled only once real download
    | authorization and streaming (P4-C4/C5) are merged and a valid HTTPS
    | download base URL is configured.
    |
    */

    'enabled' => (bool) env('DELIVERY_PIPELINE_ENABLED', false),

    'grant' => [
        // Validated bounds live in App\Support\DeliveryConfig (fail-closed).
        'ttl_minutes' => (int) env('DELIVERY_GRANT_TTL_MINUTES', 10080),
        'max_downloads' => (int) env('DELIVERY_GRANT_MAX_DOWNLOADS', 5),
    ],

    'job' => [
        // Uniqueness window for the per-order SecureDeliveryJob.
        'unique_seconds' => (int) env('DELIVERY_JOB_UNIQUE_SECONDS', 3600),
    ],

    'attempt' => [
        'ttl_seconds' => (int) env('DELIVERY_ATTEMPT_TTL_SECONDS', 900),
    ],

    'rate_limit' => [
        'authorize_per_minute' => (int) env('DELIVERY_AUTH_RATE_LIMIT_PER_MINUTE', 10),
    ],

    'audit' => [
        // HMAC secret is mandatory at the HTTP boundary and never persisted.
        'ip_hash_key' => env('DELIVERY_IP_HASH_KEY'),
        'ip_hash_key_version' => (int) env('DELIVERY_IP_HASH_KEY_VERSION', 1),
    ],

    'log' => [
        'retention_days' => (int) env('DELIVERY_LOG_RETENTION_DAYS', 90),
    ],

    'private_disks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DELIVERY_PRIVATE_DISKS', 'private')),
    ))),

    // Required (and HTTPS-bound outside local/testing) only when the pipeline is
    // enabled. The final download route is wired in P4-C4/C5.
    'download_base_url' => env('DELIVERY_DOWNLOAD_BASE_URL'),

    'require_https' => ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),

];
