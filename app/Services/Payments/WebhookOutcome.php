<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The HTTP-facing outcome of handling a provider webhook (P3-D4, D-034).
 *
 * It deliberately does NOT distinguish paid / review / ignored / processing:
 * the response must never reveal the local financial state of an order or the
 * existence of a payment. Only genuinely different transport situations map to
 * different status codes.
 */
enum WebhookOutcome: string
{
    /** Received and handled (paid, diverted to review, processing, ignored — all 200). */
    case Accepted = 'accepted';

    /** A duplicate of an already-terminal event; not reprocessed (200). */
    case Replayed = 'replayed';

    /** The signature did not verify; the event was recorded in minimal form (401). */
    case SignatureRejected = 'signature_rejected';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Accepted, self::Replayed => 200,
            self::SignatureRejected => 401,
        };
    }
}
