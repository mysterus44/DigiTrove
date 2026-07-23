<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

/**
 * The provider-agnostic outcome of a server-side payment verification (P3-D4,
 * D-034).
 *
 * Every adapter maps its own vendor labels onto exactly these cases inside the
 * adapter; the confirmation service never sees a raw provider status. `Unknown`
 * is the fail-closed default: it never confirms money.
 */
enum NormalizedPaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
