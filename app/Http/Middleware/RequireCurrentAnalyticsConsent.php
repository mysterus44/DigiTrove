<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AnalyticsConfig;
use App\Support\AnalyticsConsent;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RequireCurrentAnalyticsConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! AnalyticsConfig::enabled() || ! AnalyticsConsent::granted($request)) {
                return response()->noContent();
            }

            AnalyticsConfig::assertReady($request);
        } catch (RuntimeException) {
            return response()->noContent();
        }

        return $next($request);
    }
}
