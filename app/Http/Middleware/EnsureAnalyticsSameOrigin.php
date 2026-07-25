<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAnalyticsSameOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $fetchSite = strtolower((string) $request->header('Sec-Fetch-Site'));

        if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
            abort(403);
        }

        $origin = $request->header('Origin');
        if (is_string($origin) && ! hash_equals(strtolower($request->getSchemeAndHttpHost()), strtolower(rtrim($origin, '/')))) {
            abort(403);
        }

        return $next($request);
    }
}
