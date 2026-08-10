<?php

return [
    'foundation_enabled' => env('CRM_FOUNDATION_ENABLED', false),
    'marketing_policy_version' => env('CRM_MARKETING_POLICY_VERSION'),
    'order_attribution' => [
        'processing_enabled' => env('CRM_ORDER_ATTRIBUTION_PROCESSING_ENABLED', false),
        'batch_size' => env('CRM_ORDER_ATTRIBUTION_BATCH_SIZE', 50),
    ],
    'commerce_rollup_refresh' => [
        'processing_enabled' => env('CRM_COMMERCE_ROLLUP_REFRESH_PROCESSING_ENABLED', false),
        'batch_size' => env('CRM_COMMERCE_ROLLUP_REFRESH_BATCH_SIZE', 50),
    ],
    'commerce_rollup_backfill' => [
        'enabled' => env('CRM_COMMERCE_ROLLUP_BACKFILL_ENABLED', false),
    ],
    'segment_rebuild' => [
        'enabled' => env('CRM_SEGMENT_REBUILD_ENABLED', false),
        'processing_enabled' => env('CRM_SEGMENT_REBUILD_PROCESSING_ENABLED', false),
        'batch_size' => env('CRM_SEGMENT_REBUILD_BATCH_SIZE', 50),
    ],
    // P6-C — cart abandonment and reminders. THREE INDEPENDENT switches, all off by
    // default: detecting abandonment, queuing a reminder and actually sending one are
    // separate capabilities, and enabling one must never enable another.
    //
    // No marketing cadence is decided here. Every window, cap and cooldown is an
    // operator setting with hard bounds, validated before any write.
    'cart_reminders' => [
        'detection_enabled' => env('CART_ABANDONMENT_DETECTION_ENABLED', false),
        'enqueue_enabled' => env('CART_REMINDER_ENQUEUE_ENABLED', false),
        'send_enabled' => env('CART_REMINDER_SEND_ENABLED', false),
        'purge_enabled' => env('CART_REMINDER_PURGE_ENABLED', false),
        'inactivity_minutes' => env('CART_ABANDONMENT_INACTIVITY_MINUTES'),
        'batch_size' => env('CART_REMINDER_BATCH_SIZE', 50),
        'max_step' => env('CART_REMINDER_MAX_STEP'),
        'cooldown_minutes' => env('CART_REMINDER_COOLDOWN_MINUTES'),
        'capability_ttl_minutes' => env('CART_REMINDER_CAPABILITY_TTL_MINUTES'),
        'retention_days' => env('CART_REMINDER_RETENTION_DAYS'),
    ],

    // P6-B1 — three INDEPENDENT switches, all off by default: requesting an export,
    // processing the queue, and purging expired artefacts are separate capabilities and
    // must be enable-able separately.
    'exports' => [
        'enabled' => env('CRM_EXPORTS_ENABLED', false),
        'processing_enabled' => env('CRM_EXPORT_PROCESSING_ENABLED', false),
        'purge_enabled' => env('CRM_EXPORT_PURGE_ENABLED', false),
        'max_rows' => env('CRM_EXPORT_MAX_ROWS', 10000),
        'ttl_hours' => env('CRM_EXPORT_TTL_HOURS', 24),
        'disk' => env('CRM_EXPORT_DISK', 'private'),
    ],
];
