<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Services\Pricing\DiscountAllocator;
use App\Support\AffiliateConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6-D3. Splits a succeeded refund across the order lines, then hands the split to the
 * PostgreSQL authority that turns it into signed ledger movements.
 *
 * ⚠️ THE ADAPTER, NOT A SECOND HAMILTON. `App\Services\Pricing\DiscountAllocator` is the one
 * largest-remainder implementation in this repository (D-030 Q3) and is NOT modified: an
 * authority validated by P3-D does not bend for a downstream caller, the caller bends for it
 * — the same principle that pushed the attribution trigger off `orders` in P6-D2.
 *
 * Two adaptations are needed, and both are deliberate:
 *
 *   1. The allocator refuses `product_id < 1`, but `order_items.product_id` is NULLABLE
 *      (`ON DELETE SET NULL`, D-006) and a refund always concerns historical lines. Where it
 *      is NULL we pass the `order_item.id` itself. The allocator uses `product_id` ONLY to
 *      break ties in the remainder distribution — it never enters an arithmetic operation —
 *      so a stable, deterministic positive integer preserves the contract exactly. It is a
 *      SORT SUBSTITUTE, never a product identity, and it is never persisted anywhere.
 *   2. The allocator refuses an amount greater than the eligible subtotal. The base is
 *      `SUM(order_items.line_total_minor)`, never `orders.total_minor`: it must stay in the
 *      same value space that produced the accrual, or a future non-zero `tax_minor` would
 *      silently dilute every reversal with money that never entered a commission. A refund
 *      exceeding that sum is CAPPED to it — only the part imputable to the lines can reverse
 *      a commission, and the surplus reverses nothing.
 */
final class AffiliateRefundReversalService
{
    public function __construct(private readonly DiscountAllocator $allocator) {}

    /**
     * @return string one of `reversed`, `already_reversed`, `no_commissions`,
     *                `no_such_refund`, `refund_not_succeeded`, `no_lines`, `disabled`
     */
    public function reverseForRefund(int $refundId): string
    {
        if (! AffiliateConfig::governanceEnabled()) {
            return 'disabled';
        }

        $refund = DB::table('refunds')
            ->join('payments', 'payments.id', '=', 'refunds.payment_id')
            ->where('refunds.id', $refundId)
            ->select(['refunds.status', 'refunds.amount_minor', 'payments.order_id'])
            ->first();

        if ($refund === null) {
            return $this->logged($refundId, 'no_such_refund');
        }

        if ($refund->status !== 'succeeded') {
            return $this->logged($refundId, 'refund_not_succeeded');
        }

        $lines = DB::table('order_items')
            ->where('order_id', $refund->order_id)
            ->orderBy('id')
            ->get(['id', 'product_id', 'line_total_minor']);

        if ($lines->isEmpty()) {
            return $this->logged($refundId, 'no_lines');
        }

        $shares = [];
        $eligibleSubtotal = 0;

        foreach ($lines as $line) {
            $shares[] = [
                'line_id' => (int) $line->id,
                // Sort substitute for a deleted product — see the class comment.
                'product_id' => (int) ($line->product_id ?? $line->id),
                'subtotal_minor' => (int) $line->line_total_minor,
            ];

            $eligibleSubtotal += (int) $line->line_total_minor;
        }

        $allocatable = min((int) $refund->amount_minor, $eligibleSubtotal);

        if ($allocatable <= 0) {
            return $this->logged($refundId, 'no_lines');
        }

        /** @var array<int, int> $allocation keyed by order_item id */
        $allocation = $this->allocator->allocate($allocatable, $shares);

        $status = (string) DB::selectOne(
            'SELECT public.apply_affiliate_refund_reversal(?, ?::jsonb) AS status',
            [
                $refundId,
                // Cast to object so the payload is ALWAYS a JSON object. An int-keyed PHP
                // array whose keys happened to be 0,1,2… would encode as a JSON array, and
                // the authority refuses anything that is not an object.
                json_encode((object) $allocation, JSON_THROW_ON_ERROR),
            ],
        )->status;

        return $this->logged($refundId, $status);
    }

    /** Internal observability only: the refund id and an outcome, never an amount. */
    private function logged(int $refundId, string $status): string
    {
        Log::info('affiliate.commission.reversal', ['refund_id' => $refundId, 'status' => $status]);

        return $status;
    }
}
