# Refund provider setup (P4-C2, D-035) — SCAFFOLD ONLY

> **Status: NOT IMPLEMENTED.** There is no refund provider adapter. This file is
> a scaffold of placeholders. `RefundCompletionService` is a **local primitive**
> that finalises a refund only AFTER a future adapter has confirmed it with the
> provider — it makes no network call itself. No CinetPay or PowerPay refund
> endpoint has been invented anywhere.

## Environment variables (reserved, unused today)

```dotenv
REFUND_PROVIDER_DRIVER=
REFUND_PROVIDER_BASE_URL=
REFUND_PROVIDER_CREATE_PATH=
REFUND_PROVIDER_CHECK_PATH=
REFUND_PROVIDER_API_KEY=
REFUND_PROVIDER_MERCHANT_ID=
REFUND_PROVIDER_WEBHOOK_SECRET=
```

## TODO(PROVIDER-OFFICIAL-DOC) — before implementing an adapter

- confirm the refund **creation** endpoint;
- confirm the refund **verification** endpoint;
- confirm the authentication method;
- confirm the webhook signature algorithm;
- confirm the amount/currency format (integer minor units — **no float ever**);
- confirm the provider status labels;
- confirm the refund reference field;
- confirm the idempotency policy.

Only once these are filled from the official documentation and covered by tests
may a real refund adapter call `RefundCompletionService::completeSucceededRefund()`.
