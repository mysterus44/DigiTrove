<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * A server-side payment confirmation could not proceed, or was deliberately
 * diverted to manual review (P3-D4/P3-D5, D-034).
 *
 * Messages are generic: no SQLSTATE, no constraint name, no SQL, no internal
 * id, no provider response body, no HMAC secret, no `x-token`, no API key and
 * no raw webhook payload. The HTTP layer maps `reason` to a stable response.
 */
final class PaymentConfirmationException extends RuntimeException
{
    private function __construct(
        public readonly PaymentConfirmationRefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(PaymentConfirmationRefusalReason $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
