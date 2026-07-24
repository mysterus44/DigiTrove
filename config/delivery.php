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
        'file_per_minute' => (int) env('DELIVERY_FILE_RATE_LIMIT_PER_MINUTE', 60),
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

    'file' => [
        'driver' => env('DELIVERY_FILE_DRIVER', 'stream'),
        'stream_chunk_bytes' => (int) env('DELIVERY_STREAM_CHUNK_BYTES', 1_048_576),
    ],

    'acceleration' => [
        'driver' => env('DELIVERY_ACCELERATION_DRIVER', 'none'),
        'x_accel_prefix' => env('DELIVERY_X_ACCEL_PREFIX'),
        'x_accel_min_bytes' => (int) env('DELIVERY_X_ACCEL_MIN_BYTES', 0),
    ],

    'operations' => [
        'started_reconcile_minutes' => (int) env('DELIVERY_STARTED_RECONCILE_MINUTES', 30),
        'revoked_grant_retention_days' => (int) env('DELIVERY_REVOKED_GRANT_RETENTION_DAYS', 365),
        'abuse_window_hours' => (int) env('DELIVERY_ABUSE_WINDOW_HOURS', 24),
        'abuse_distinct_ip_threshold' => (int) env('DELIVERY_ABUSE_DISTINCT_IP_THRESHOLD', 3),
        'batch_size' => (int) env('DELIVERY_OPS_BATCH_SIZE', 500),
    ],

    // Required (and HTTPS-bound outside local/testing) only when the pipeline is
    // enabled. The final download route is wired in P4-C4/C5.
    'download_base_url' => env('DELIVERY_DOWNLOAD_BASE_URL'),

    'require_https' => ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),

];
