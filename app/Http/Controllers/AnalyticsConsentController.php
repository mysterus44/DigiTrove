<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnalyticsConsentRequest;
use App\Support\AnalyticsConfig;
use App\Support\AnalyticsConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

final class AnalyticsConsentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(AnalyticsConsent::state($request));
    }

    public function store(StoreAnalyticsConsentRequest $request): Response
    {
        $consent = (string) $request->validated('consent');
        $response = response()->noContent();
        $response->headers->setCookie($this->cookie(
            AnalyticsConfig::CONSENT_COOKIE,
            AnalyticsConsent::encode($consent),
            525_600,
        ));

        if ($consent === AnalyticsConsent::DENIED) {
            $this->forgetAnalyticsIdentity($response);
        }

        return $response;
    }

    public function destroy(): Response
    {
        $response = response()->noContent();
        $response->headers->setCookie($this->cookie(
            AnalyticsConfig::CONSENT_COOKIE,
            AnalyticsConsent::encode(AnalyticsConsent::DENIED),
            525_600,
        ));
        $this->forgetAnalyticsIdentity($response);

        return $response;
    }

    private function forgetAnalyticsIdentity(Response $response): void
    {
        foreach ([AnalyticsConfig::SESSION_COOKIE, AnalyticsConfig::VISITOR_COOKIE] as $name) {
            $response->headers->setCookie(Cookie::create(
                $name,
                '',
                1,
                '/',
                null,
                AnalyticsConfig::cookieSecure(),
                true,
                false,
                Cookie::SAMESITE_STRICT,
            ));
        }
    }

    private function cookie(string $name, string $value, int $minutes): Cookie
    {
        return Cookie::create(
            $name,
            $value,
            now()->addMinutes($minutes),
            '/',
            null,
            AnalyticsConfig::cookieSecure(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }
}
