<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Services\Cart\GuestVisitorContext;
use App\Support\AffiliateConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6-D2. Records an affiliate touch through the PostgreSQL authority.
 *
 * The PHP side never reads `affiliate_codes`, never decides whether a code is valid and
 * never writes `affiliate_touches`: `record_affiliate_touch` owns all of it, and the
 * runtime holds `EXECUTE` on it and no DML anywhere in the affiliate block.
 */
final class AffiliateTouchCaptureService
{
    public function __construct(private readonly GuestVisitorContext $visitors) {}

    public function recordLink(string $code): string
    {
        return $this->record($code, 'link');
    }

    public function recordCode(string $code): string
    {
        return $this->record($code, 'code');
    }

    /**
     * @return string one of `recorded`, `already_active`, `no_such_code`,
     *                `no_active_policy`, `disabled`
     */
    private function record(string $code, string $source): string
    {
        // One flag governs the whole affiliate surface (see `AffiliateConfig`): capture must
        // not be able to run while governance is shut.
        if (! AffiliateConfig::governanceEnabled()) {
            return 'disabled';
        }

        $user = Auth::user();

        $status = (string) DB::selectOne(
            'SELECT public.record_affiliate_touch(?, ?, ?, ?) AS status',
            [
                $this->visitors->forMutation()->id,
                $user?->getAuthIdentifier(),
                $code,
                $source,
            ],
        )->status;

        // Internal observability only — the HTTP response stays identical whatever this says,
        // so a visitor can never probe which codes exist. An affiliate code is public by
        // design (it travels in shared links); the visitor identity is NOT logged.
        Log::info('affiliate.touch', ['code' => $code, 'source' => $source, 'status' => $status]);

        return $status;
    }
}
