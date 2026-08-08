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
];
