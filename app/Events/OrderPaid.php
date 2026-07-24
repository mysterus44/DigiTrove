<?php

declare(strict_types=1);

namespace App\Events;

/**
 * An order has just transitioned to `paid` (P3-D5, D-034).
 *
 * It carries ONLY the internal order id — no model, no public id, no e-mail, no
 * coupon, no payment, no provider data, no token, no file, no URL. Downstream
 * consumers (P4-C delivery) resolve everything else from the id under their own
 * authorization.
 *
 * Dispatch semantics (see D-034): fired exactly once per real local
 * `pending → paid` transition, AFTER COMMIT, never on replay or rollback, never
 * for `payment_review`/pending/processing/failed/cancelled/unknown. A process
 * crash between COMMIT and dispatch is a residual window; durable reconciliation
 * belongs to the future P4-C/Ops flow. P4-C0 adds one minimal, feature-gated
 * listener that dispatches an order-id-only delivery job.
 */
final class OrderPaid
{
    public function __construct(
        public readonly int $orderId,
    ) {}
}
