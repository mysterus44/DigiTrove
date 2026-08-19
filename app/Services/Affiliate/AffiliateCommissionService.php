<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Support\AffiliateConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6-D3. Accrues commissions for a paid order and promotes due ones to payable.
 *
 * The PHP side computes NO money here: it does not read a rate, does not multiply, does not
 * round. `accrue_affiliate_commissions` and `promote_affiliate_commissions_to_payable` are
 * the authorities, and the runtime holds `EXECUTE` on them and no DML anywhere in the
 * affiliate block — not even `SELECT`.
 */
final class AffiliateCommissionService
{
    /**
     * @return string one of `accrued`, `already_accrued`, `no_attribution`, `no_such_order`,
     *                `not_paid`, `no_such_policy`, `no_commissionable_line`, `disabled`
     */
    public function accrueForOrder(int $orderId): string
    {
        // Re-read at execution time, not only at dispatch: switching the programme off must
        // also stop jobs that are already queued. Same kill-switch rule as P4-C delivery.
        if (! AffiliateConfig::governanceEnabled()) {
            return 'disabled';
        }

        $status = (string) DB::selectOne(
            'SELECT public.accrue_affiliate_commissions(?) AS status',
            [$orderId],
        )->status;

        // Internal observability only. `order_id` alone — no affiliate, no amount, no rate.
        Log::info('affiliate.commission.accrual', ['order_id' => $orderId, 'status' => $status]);

        return $status;
    }

    /** @return int how many commissions moved from `pending` to `payable`. */
    public function promoteDue(int $limit): int
    {
        if (! AffiliateConfig::governanceEnabled()) {
            return 0;
        }

        $promoted = (int) DB::selectOne(
            'SELECT public.promote_affiliate_commissions_to_payable(?) AS promoted',
            [$limit],
        )->promoted;

        if ($promoted > 0) {
            Log::info('affiliate.commission.promoted', ['count' => $promoted]);
        }

        return $promoted;
    }
}
