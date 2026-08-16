<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Guest cart lifetime
    |--------------------------------------------------------------------------
    |
    | How long a cart stays usable, in DAYS. `carts.expires_at` is NOT NULL and has no
    | database DEFAULT, so a value has to be supplied at creation; the only figure that
    | existed in the repository before this gate was `CartFactory`'s seven days, which is
    | a test fixture and not a product decision.
    |
    | Digital goods carry no stock pressure, so nothing justifies an artificial deadline —
    | but a forgotten cart should still expire rather than live in the database for ever.
    | The stamp is written once, at creation, and never recomputed: extending it on every
    | visit would make expiry unreachable for exactly the carts that need it.
    |
    | Same shape as `config/checkout.php` — whole days, at least one, invalid value
    | refused before any write rather than silently defaulted.
    |
    */

    'ttl_days' => env('CART_TTL_DAYS', 14),

];
