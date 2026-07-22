<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pending order time to live
    |--------------------------------------------------------------------------
    |
    | How long a `pending` order stays claimable before it expires, in WHOLE
    | MINUTES. `orders.expires_at` is NOT NULL without a database DEFAULT (no
    | commercial policy lives in the schema — D-029.1-B), so the value is set
    | here and can be overridden per environment without touching the code.
    |
    | Default: 30 minutes (D-032). Minimum: 1. An invalid value is a server
    | misconfiguration and fails the checkout before any business write.
    |
    */

    'pending_ttl_minutes' => env('CHECKOUT_PENDING_TTL_MINUTES', 30),

];
