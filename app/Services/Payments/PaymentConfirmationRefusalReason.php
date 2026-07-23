<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The exhaustive set of reasons a server-side confirmation may refuse or
 * divert (P3-D4/P3-D5, D-034).
 *
 * The future HTTP layer maps each case to a stable, generic response; the label
 * itself never leaks a SQLSTATE, a constraint name, a provider body or a secret.
 */
enum PaymentConfirmationRefusalReason: string
{
    // Webhook ingress.
    case WebhookInvalid = 'webhook_invalid';
    case WebhookReplay = 'webhook_replay';

    // Target resolution.
    case PaymentUnavailable = 'payment_unavailable';

    // Provider counter-call.
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderProtocolFailure = 'provider_protocol_failure';
    case ProviderConfigurationFailure = 'provider_configuration_failure';

    // Cross-checks against the server snapshot.
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case ReferenceMismatch = 'reference_mismatch';

    // Diversion — money confirmed but the local state cannot safely become paid.
    case ConfirmationRequiresReview = 'confirmation_requires_review';

    // Coupon consumption.
    case CouponUnavailable = 'coupon_unavailable';

    // Free-order finalisation.
    case FreeOrderInvalid = 'free_order_invalid';

    // Catch-all for any unexpected database/model failure, always sanitised.
    case IntegrityFailure = 'integrity_failure';
}
