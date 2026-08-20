<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Initial administrator (H2.3)
    |--------------------------------------------------------------------------
    |
    | Read by `Database\Seeders\AdminSeeder` and by nothing else. There are no
    | default values: the seeder REFUSES and stops when either is missing, and it
    | never falls back to a password of its own.
    |
    | ⚠️ These live in config rather than being read with `env()` from the seeder
    | for a concrete reason: `php artisan config:cache` — which is exactly what a
    | production deployment runs — makes `env()` return null everywhere outside
    | config files. A seeder calling `env()` directly would refuse on a correctly
    | configured server, fail-closed for entirely the wrong reason, and block a
    | legitimate deployment while looking like a security feature.
    |
    | The refusal rules (length, blocklist, no relation to the address) live in
    | App\Support\AdminCredentialPolicy, on the same pattern as DeliveryConfig and
    | SessionCookiePolicy: config declares, a named class validates.
    |
    */

    'email' => env('ADMIN_EMAIL'),

    'password' => env('ADMIN_PASSWORD'),

];
