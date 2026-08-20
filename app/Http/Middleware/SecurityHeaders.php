<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every response (H2.1, durcissement pré-production).
 *
 * ⚠️ IT NEVER OVERWRITES A HEADER ALREADY SET. Five controllers — download landing,
 * download file, download authorisation, cart resume and CRM export — already ship a far
 * stricter, nonce-based `default-src 'none'` policy for pages that must load nothing at
 * all. This middleware is a FLOOR, not a ceiling: where a response has already decided,
 * its decision stands. Overwriting them would silently downgrade the most sensitive pages
 * in the application to the loosest policy in it.
 *
 * ⚠️ `'unsafe-inline'` on `script-src` means this CSP is NOT an XSS backstop. It is a
 * supply-chain and clickjacking control: no external origin can load script, style, font
 * or image, the page cannot be framed, `<base>` cannot be rewritten, and no form can post
 * off-site. The XSS defence remains where it has always been — Blade escaping, and
 * `ArticleContent::toHtml()` stripping HTML at the CommonMark level. Do not read this
 * header as permission to relax either.
 */
final class SecurityHeaders
{
    /**
     * The public policy. No `'unsafe-eval'`: nothing on the storefront needs it, and the
     * storefront is the surface an anonymous visitor can actually reach.
     */
    private const PUBLIC_SCRIPT_SRC = "script-src 'self' 'unsafe-inline'";

    /**
     * The admin panel policy.
     *
     * ⚠️ `'unsafe-eval'` IS REQUIRED HERE, AND THAT WAS MEASURED IN A REAL BROWSER, not
     * deduced. Filament drives its UI through Alpine, which evaluates every `x-data`,
     * `x-show` and `x-bind` expression as JavaScript. Without `'unsafe-eval'` the console
     * fills with `EvalError` and the panel is not merely degraded — it is DEAD: the
     * password field never initialises, the submit button never binds, modals never open.
     * No test in the suite can see this, because the failure is in the browser's JavaScript
     * engine and the HTML is served perfectly either way.
     *
     * Scoped to the admin path on purpose. The panel sits behind an authenticated admin
     * gate; the storefront does not, and must not inherit this relaxation.
     */
    private const ADMIN_SCRIPT_SRC = "script-src 'self' 'unsafe-inline' 'unsafe-eval'";

    /**
     * The Vite dev server, allowed in `local` only.
     *
     * Measured the same way: with a strict policy the HMR client on `:5173` is blocked and
     * the developer silently loses live reload. In production `@vite` emits built assets
     * from the same origin, so `'self'` already covers it and this never applies.
     */
    private const VITE_DEV_ORIGINS = 'http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173';

    /** One year, with subdomains. Preload is deliberately NOT claimed: it is irreversible. */
    private const STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($request),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            // Sends the origin cross-site, the full path same-site: enough for internal
            // analytics, never leaking a download or cart-resume path to a third party.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        // HSTS only over a real HTTPS connection. Announced on plain HTTP it is ignored by
        // browsers anyway, and in local/testing it would make an http:// dev server
        // unreachable for a year on the developer's own machine.
        if ($request->secure() && ! app()->environment(['local', 'testing'])) {
            $headers['Strict-Transport-Security'] = self::STRICT_TRANSPORT_SECURITY;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $scriptSrc = $this->isAdminPanel($request) ? self::ADMIN_SCRIPT_SRC : self::PUBLIC_SCRIPT_SRC;
        $styleSrc = "style-src 'self' 'unsafe-inline'";
        $connectSrc = "connect-src 'self'";

        if (app()->environment('local')) {
            $scriptSrc .= ' '.self::VITE_DEV_ORIGINS;
            $styleSrc .= ' '.self::VITE_DEV_ORIGINS;
            $connectSrc .= ' '.self::VITE_DEV_ORIGINS;
        }

        return implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            $styleSrc,
            "img-src 'self' data:",
            "font-src 'self' data:",
            $connectSrc,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The admin path comes from the panel itself, never from a hard-coded string: moving
     * the panel must move this boundary with it, not silently leave the admin under a
     * policy that breaks it.
     */
    private function isAdminPanel(Request $request): bool
    {
        try {
            $path = trim(Filament::getPanel('admin')->getPath(), '/');
        } catch (\Throwable) {
            // No panel resolved (a console request, a boot-time failure): fall back to the
            // stricter public policy rather than the looser admin one.
            return false;
        }

        return $path !== '' && $request->is($path, $path.'/*');
    }
}
