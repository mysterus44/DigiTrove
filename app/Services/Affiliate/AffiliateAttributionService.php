<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Support\AffiliateConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6-D2. Invokes the attribution authority for one paid order.
 *
 * The PHP side decides NOTHING: it does not read touches, does not rank them and does not
 * write `affiliate_attributions`. `resolve_affiliate_attribution` is the single authority,
 * and the runtime holds `EXECUTE` on it and no DML anywhere in the affiliate block.
 *
 * Called from `ProcessAffiliateAttribution` in the worker, never from the payment
 * confirmation request — see the job for why that separation matters.
 */
final class AffiliateAttributionService
{
    /**
     * @return string one of `attributed`, `already_attributed`, `no_active_policy`,
     *                `no_match`, `no_such_order`, `disabled`
     */
    public function resolveForOrder(int $orderId): string
    {
        // Re-read at execution time, not only at dispatch: turning the programme off must
        // also stop jobs that are already queued. Same kill-switch rule as P4-C delivery.
        if (! AffiliateConfig::governanceEnabled()) {
            return 'disabled';
        }

        $status = (string) DB::selectOne(
            'SELECT public.resolve_affiliate_attribution(?) AS status',
            [$orderId],
        )->status;

        // Internal observability only. `order_id` alone — no identity, no e-mail, no amount.
        // Without this, a programme whose policy was never activated stays invisible until
        // an affiliate complains about never being credited.
        Log::info('affiliate.attribution', ['order_id' => $orderId, 'status' => $status]);

        return $status;
    }
}
