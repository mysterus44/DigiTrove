<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Affiliate\AffiliateCommissionService;
use App\Support\AffiliateConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled sweep moving due commissions from `pending` to `payable` (P6-D3).
 *
 * A sweep, never a status derived at read time: the transition then carries a real,
 * auditable `updated_at`, which a computed status could never leave behind. The index
 * `affiliate_commissions_status_payable_index` exists precisely for this scan.
 *
 * Fail-closed: with the affiliate surface disabled it promotes nothing.
 */
final class PromoteAffiliateCommissions extends Command
{
    protected $signature = 'affiliate:promote-commissions {--limit=100}';

    protected $description = 'Promote due affiliate commissions from pending to payable';

    public function handle(AffiliateCommissionService $commissions): int
    {
        try {
            if (! AffiliateConfig::governanceEnabled()) {
                $this->line('promoted=0');

                return self::SUCCESS;
            }

            $limit = (int) $this->option('limit');

            if ($limit < 1 || $limit > 1000) {
                $this->line('promoted=0');

                return self::FAILURE;
            }

            $this->line('promoted='.$commissions->promoteDue($limit));

            return self::SUCCESS;
        } catch (Throwable) {
            // No exception detail on stdout: it could carry an amount or an affiliate id.
            $this->line('promoted=0');

            return self::FAILURE;
        }
    }
}
