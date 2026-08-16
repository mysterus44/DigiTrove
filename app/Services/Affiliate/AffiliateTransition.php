<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

/**
 * What an authority reports after moving an affiliate.
 *
 * `issuedCode` is present only for the transitions that mint one — approval and
 * reactivation. It is the plain value, returned once, so the administrator can pass it on;
 * nothing here re-derives it, and no transition ever returns an existing code.
 */
final readonly class AffiliateTransition
{
    public function __construct(
        public int $affiliateId,
        public string $status,
        public ?string $issuedCode = null,
    ) {}
}
