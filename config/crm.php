<?php

return [
    'foundation_enabled' => env('CRM_FOUNDATION_ENABLED', false),
    'marketing_policy_version' => env('CRM_MARKETING_POLICY_VERSION'),
    'order_attribution' => [
        'processing_enabled' => env('CRM_ORDER_ATTRIBUTION_PROCESSING_ENABLED', false),
        'batch_size' => env('CRM_ORDER_ATTRIBUTION_BATCH_SIZE', 50),
    ],
];
