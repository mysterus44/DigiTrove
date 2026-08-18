<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Services\Affiliate\AffiliateTouchCaptureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * P6-D2 storefront capture. Deliberately mute: every outcome — recorded, already active,
 * unknown code, programme switched off — produces the SAME response, so the endpoint can
 * never be used to probe which affiliate codes exist. The status is logged internally.
 */
final class AffiliateTouchController
{
    public function __construct(private readonly AffiliateTouchCaptureService $touches) {}

    public function resolveLink(Request $request, string $code): RedirectResponse
    {
        $this->touches->recordLink($code);

        return redirect($this->safeDestination($request->query('dest')));
    }

    public function storeCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Mirrors `affiliate_codes_format_check` (`^[A-Z0-9]{4,32}$`); case-insensitive
            // because the authority upper-cases the parameter before comparing.
            'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{4,32}\z/'],
        ]);

        $this->touches->recordCode($validated['code']);

        return redirect()->route('cart.show');
    }

    /**
     * An internal path only. `str_starts_with($dest, '/')` alone is NOT enough: `//evil.test`
     * and `/\evil.test` also start with a slash and browsers read them as protocol-relative
     * URLs, which turns a referral link into an open redirect.
     */
    private function safeDestination(mixed $dest): string
    {
        if (! is_string($dest) || ! str_starts_with($dest, '/')) {
            return route('storefront.home');
        }

        if (str_starts_with($dest, '//') || str_starts_with($dest, '/\\')) {
            return route('storefront.home');
        }

        return $dest;
    }
}
