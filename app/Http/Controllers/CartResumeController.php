<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Crm\CartReminderDispatcher;
use App\Services\Crm\CartReminderService;
use App\Support\CartReminderConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * P6-C cart resume surface.
 *
 * It reuses the P4-C exchange pattern verbatim, because that pattern is already merged
 * and proven: a DB-free bootstrap page with a per-response CSP nonce, whose script reads
 * the capability from the URI FRAGMENT and calls `history.replaceState` BEFORE doing
 * anything with it.
 *
 * WHY THE FRAGMENT. A fragment is never placed in the HTTP request line and never sent
 * in `Referer`, so neither this server nor any reverse proxy in front of it ever sees
 * the capability in a URL. It reaches the server exactly once, in a POST body.
 *
 * WHAT THIS IS NOT. There is no storefront: this surface establishes a short, opaque,
 * server-side continuation and says so. It cannot modify a cart or check out, and it
 * deliberately exposes no cart contents.
 */
final class CartResumeController
{
    /** The session key holding the resumed cart. Opaque to the client. */
    public const SESSION_KEY = 'p6c.resumed_cart_public_id';

    /**
     * Bootstrap page. Receives NO secret: the capability is in the fragment, which the
     * browser keeps to itself.
     */
    public function show(string $cartPublicId): Response
    {
        $nonce = base64_encode(random_bytes(18));

        // Rendered without touching the database: the page must reveal nothing about
        // whether this cart, attempt or customer exists.
        $content = view('carts.resume', [
            'cartPublicId' => $cartPublicId,
            'nonce' => $nonce,
        ])->render();

        return response($content)->withHeaders($this->securityHeaders($nonce));
    }

    /**
     * Redemption. The capability arrives here once, in the POST body, and is compared
     * only as a digest.
     */
    public function redeem(Request $request): Response|RedirectResponse
    {
        try {
            CartReminderConfig::assertEnabledForResume();
        } catch (Throwable) {
            return $this->refuse();
        }

        $cartPublicId = $request->input('cart');
        $capability = $request->input('c');

        // Bounded shape check BEFORE any lookup. A malformed input must cost nothing and
        // must be indistinguishable from a wrong one.
        if (! is_string($cartPublicId) || ! is_string($capability)
            || preg_match('/\A[0-9a-fA-F-]{36}\z/', $cartPublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $capability) !== 1) {
            return $this->refuse();
        }

        try {
            $resolved = app(CartReminderService::class)
                ->resolveBySecret(CartReminderDispatcher::digest($capability));
        } catch (Throwable) {
            return $this->refuse();
        }

        // The capability must belong to THIS cart: presenting a valid capability against
        // another cart's public id is refused, not silently redirected.
        if ($resolved === null || ! hash_equals($resolved['cart_public_id'], $cartPublicId)) {
            return $this->refuse();
        }

        // The continuation: a short, opaque, server-side reference to the cart. The raw
        // capability is never put back into a URL, and the session cookie carries no
        // cart data of its own.
        $request->session()->put(self::SESSION_KEY, $resolved['cart_public_id']);
        $request->session()->regenerate();

        return redirect('/cart/resumed');
    }

    /** Neutral landing. Says the resume succeeded and nothing else. */
    public function resumed(Request $request): Response
    {
        $resumed = $request->session()->get(self::SESSION_KEY);

        return response()
            ->view('carts.resumed', ['resumed' => is_string($resumed)])
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    /**
     * ONE refusal for every failure mode — unknown cart, wrong capability, expired,
     * revoked, converted, mismatched. Distinguishable refusals would turn this endpoint
     * into an oracle for which carts and capabilities exist.
     */
    private function refuse(): Response
    {
        return response()->view('carts.resume-unavailable', [], 404)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, string> */
    private function securityHeaders(string $nonce): array
    {
        return [
            // Scoped to THIS response: no global CSP relaxation, and no `unsafe-inline`.
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; connect-src 'self'; style-src 'nonce-{$nonce}'; img-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
