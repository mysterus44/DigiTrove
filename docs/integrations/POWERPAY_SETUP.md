# PowerPay integration — setup guide (SCAFFOLD ONLY)

> **Status: NOT IMPLEMENTED.** PowerPay has no adapter in DigiTrove. This file is
> a scaffold. `PAYMENT_DRIVER=powerpay` **fails closed** (a
> `ProviderConfigurationFailure` is raised before any HTTP request) and will keep
> failing closed until the sections below are filled from the **official**
> PowerPay documentation and covered by tests — exactly as CinetPay is.

No PowerPay endpoint, status label, signature algorithm, or field name has been
invented anywhere in the codebase. Do not add one from memory or from a blog
post; copy it from the official documentation or the merchant dashboard.

## Environment variables (reserved, unused today)

```dotenv
POWERPAY_BASE_URL=
POWERPAY_INIT_PATH=
POWERPAY_VERIFY_PATH=
POWERPAY_API_KEY=
POWERPAY_MERCHANT_ID=
POWERPAY_WEBHOOK_SECRET=
POWERPAY_SIGNATURE_HEADER=
```

## TODO — copy from the official PowerPay documentation before implementing

- [ ] **Authentication** — TODO(POWERPAY-LIVE): auth method (header/body, key format).
- [ ] **Initialisation endpoint** — TODO(POWERPAY-LIVE): method + full path, request shape.
- [ ] **Verification endpoint** — TODO(POWERPAY-LIVE): the mandatory server counter-check.
- [ ] **Amount & currency format** — TODO(POWERPAY-LIVE): minor units? integer? confirm — **no float ever**.
- [ ] **Transaction identifier** — TODO(POWERPAY-LIVE): confirm `payment.public_id` may be the idempotency key.
- [ ] **Signature algorithm** — TODO(POWERPAY-LIVE): exact HMAC/field order + header name; must use `hash_equals`.
- [ ] **Webhook fields** — TODO(POWERPAY-LIVE): authoritative field list for the payload hash allowlist.
- [ ] **Provider statuses** — TODO(POWERPAY-LIVE): full label set → map onto `NormalizedPaymentStatus`.
- [ ] **Replay policy** — TODO(POWERPAY-LIVE): does PowerPay send a stable event id, or must we derive one?

## Implementation checklist (mirror CinetPay)

1. Add a `PowerPayProvider` implementing `PaymentProvider` + `PaymentConfirmationProvider`.
2. Isolate every vendor detail (endpoints, status map, HMAC field order) inside the adapter.
3. Read all secrets from config only; never log or expose them.
4. Add `'powerpay' => new PowerPayProvider(...)` to `PaymentProviderFactory::make()`.
5. Add unit tests with `Http::preventStrayRequests()` + `Http::fake()` — no real network.
6. Only then may `PAYMENT_DRIVER=powerpay` stop failing closed.
