<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use RuntimeException;

/**
 * The single, deliberately uninformative failure of the affiliate governance layer.
 *
 * PostgreSQL messages name constraints, functions and sometimes values. None of that
 * belongs in an administrator's browser, and none of it belongs in a log line that a
 * support ticket might carry outward. The refusal reason is carried by a typed enum;
 * the message never is.
 */
final class AffiliateOperationException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly AffiliateRefusalReason $reason,
    ) {
        parent::__construct($message);
    }

    public static function because(AffiliateRefusalReason $reason): self
    {
        return new self($reason->message(), $reason);
    }
}
