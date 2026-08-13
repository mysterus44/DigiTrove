<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

/**
 * Approve and reject are ONE decision with two outcomes, not two features.
 *
 * PostgreSQL models them that way — `review_affiliate_application` takes the verdict as an
 * argument and settles both against the same pending row under the same lock — and the
 * service keeps that shape. Splitting them into two methods would invite two divergent
 * eligibility stories in PHP, which is exactly what the single authority prevents.
 */
enum AffiliateReviewDecision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
