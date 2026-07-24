<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bounded set of reasons a download grant may be revoked (P4-C, D-035).
 *
 * Revocation is set-once and paired with exactly one of these codes; the DB
 * `download_grants_revocation_pair_check` enforces the pairing, this enum keeps
 * the vocabulary centralised.
 */
enum GrantRevocationReason: string
{
    /** The order was fully refunded. */
    case FullRefund = 'full_refund';

    /** The synchronous delivery e-mail failed; the just-issued grants are revoked. */
    case DeliveryFailed = 'delivery_failed';

    /** A retry found active grants whose raw tokens are gone; revoke then reissue. */
    case DeliveryUncertainReissue = 'delivery_uncertain_reissue';

    /** An expired but non-revoked grant is being reissued. */
    case ExpiredReissue = 'expired_reissue';

    /** A manual security-driven reissue. */
    case ManualSecurityReissue = 'manual_security_reissue';
}
