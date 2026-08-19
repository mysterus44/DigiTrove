<?php

declare(strict_types=1);

namespace App\Events;

/**
 * A refund has just been finalised as `succeeded` (P6-D3, D-068).
 *
 * It carries ONLY the internal refund id — no model, no public id, no amount, no provider
 * reference, no order, no customer. Consumers resolve everything else from the id under
 * their own authority, exactly like `OrderPaid`.
 *
 * Dispatch semantics: fired by `RefundCompletionService` AFTER COMMIT, never inside the
 * transaction, so a consumer fault can never roll a refund back. It IS fired on an
 * idempotent replay: the downstream authority is idempotent by construction (a partial
 * unique index on `(commission_id, refund_id)`), and re-firing is the only way a reversal
 * lost to a crashed worker ever gets a second chance.
 */
final class RefundSucceeded
{
    public function __construct(
        public readonly int $refundId,
    ) {}
}
