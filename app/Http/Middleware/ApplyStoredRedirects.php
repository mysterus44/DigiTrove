<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P7. Serves the stored 301s so the SEO earned by the legacy URLs survives the rewrite.
 *
 * ⚠️ IT ONLY RUNS ON A MISS. The lookup happens AFTER the application has failed to match a
 * route, so a redirect can never shadow a real page: adding a row for a path that later
 * becomes a genuine route silently stops mattering instead of breaking the site.
 *
 * The destination is trusted because the DATABASE guarantees it — internal absolute path,
 * no self-loop, no chain (`redirects_no_chain_trigger`). This middleware re-checks the
 * leading-slash shape anyway: a guarantee worth having is worth not depending on alone.
 */
final class ApplyStoredRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 404) {
            return $response;
        }

        $path = '/'.ltrim($request->getPathInfo(), '/');

        $redirect = Redirect::query()->where('from_path', $path)->first();

        if ($redirect === null) {
            return $response;
        }

        $target = $redirect->to_path;

        // Defence in depth against a row inserted by some future path that bypasses the
        // CHECKs: `//host` and `/\host` are both read as protocol-relative by browsers, so
        // either would turn a stored redirect into an open redirect.
        //
        // The second character is compared explicitly rather than through a literal `'/\'`:
        // a lone trailing backslash inside a quoted string is the kind of detail a formatter
        // or a copy can silently corrupt, and this check is a security boundary.
        $second = substr($target, 1, 1);

        if (! str_starts_with($target, '/') || $second === '/' || $second === '\\') {
            return $response;
        }

        return redirect($target, $redirect->status_code);
    }
}
