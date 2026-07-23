<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Active payment driver
    |--------------------------------------------------------------------------
    |
    | DISABLED by default. The application boots and runs with an empty driver;
    | a provider is resolved lazily and only when its configuration is complete.
    | An empty, unknown, or not-yet-implemented driver fails closed with a
    | ProviderConfigurationFailure and never issues an HTTP request.
    |
    | Supported: 'cinetpay'. 'powerpay' is reserved but intentionally refused
    | until its official contract is implemented (see docs/integrations).
    |
    */

    'driver' => env('PAYMENT_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | CinetPay (real example adapter — disabled unless selected)
    |--------------------------------------------------------------------------
    |
    | Secrets are read from the environment only; there are no default secret
    | values. Endpoints are overridable purely by configuration. HTTPS is
    | required everywhere except the local/testing environments, and TLS
    | verification is never disabled.
    |
    */

    'cinetpay' => [
        'api_key' => env('CINETPAY_API_KEY'),
        'site_id' => env('CINETPAY_SITE_ID'),
        'secret_key' => env('CINETPAY_SECRET_KEY'),
        'init_url' => env('CINETPAY_INIT_URL', 'https://api-checkout.cinetpay.com/v2/payment'),
        'check_url' => env('CINETPAY_CHECK_URL', 'https://api-checkout.cinetpay.com/v2/payment/check'),
        'channels' => env('CINETPAY_CHANNELS', 'MOBILE_MONEY'),
        'lang' => env('CINETPAY_LANG', 'fr'),
        'connect_timeout' => (int) env('CINETPAY_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CINETPAY_TIMEOUT', 15),
        'notify_url' => env('CINETPAY_NOTIFY_URL'),
        'return_url' => env('CINETPAY_RETURN_URL'),
        // HTTPS is mandatory outside local/testing; never disable TLS verification.
        'require_https' => ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ],

];
