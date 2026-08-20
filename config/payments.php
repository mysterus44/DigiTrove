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
    | Supported: 'cinetpay', 'geniuspay'. 'powerpay' is reserved but intentionally
    | refused until its official contract is implemented (see docs/integrations).
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

    /*
    |--------------------------------------------------------------------------
    | GeniusPay (real adapter — disabled unless selected)
    |--------------------------------------------------------------------------
    |
    | Same rules as CinetPay: secrets live in the environment only, there are no
    | default secret values, HTTPS is mandatory outside local/testing, and TLS
    | verification is never disabled.
    |
    | SANDBOX FIRST. `pk_sandbox_…` / `sk_sandbox_…` / `whsec_sandbox_…` are the only
    | credentials used until the flow is proven end to end. The adapter never inspects
    | the prefix and never chooses an environment: switching to `live` is a human
    | decision made in `.env`, and nowhere else.
    |
    */

    'geniuspay' => [
        // 'sandbox' or 'live'. Not a switch the adapter reads to CHOOSE anything: a
        // cross-check that refuses live credentials in a sandbox run and the reverse.
        'environment' => env('GENIUSPAY_ENVIRONMENT', 'sandbox'),
        'api_key' => env('GENIUSPAY_API_KEY'),
        'api_secret' => env('GENIUSPAY_API_SECRET'),
        'webhook_secret' => env('GENIUSPAY_WEBHOOK_SECRET'),
        'base_url' => env('GENIUSPAY_BASE_URL', 'https://pay.genius.ci/api/v1/merchant'),
        'connect_timeout' => (int) env('GENIUSPAY_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('GENIUSPAY_TIMEOUT', 15),
        // Replay window on `X-Webhook-Timestamp`, in seconds. Symmetric, so ordinary
        // NTP drift on the provider side does not drop legitimate notifications.
        'webhook_tolerance_seconds' => (int) env('GENIUSPAY_WEBHOOK_TOLERANCE', 300),
        // HTTPS is mandatory outside local/testing; never disable TLS verification.
        'require_https' => ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ],

];
