<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnalyticsEventRequest;
use App\Services\Analytics\FirstPartyAnalyticsIngestionService;
use App\Support\AnalyticsConfig;
use App\Support\AnalyticsEventRejected;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

final class AnalyticsEventController extends Controller
{
    public function __invoke(
        StoreAnalyticsEventRequest $request,
        FirstPartyAnalyticsIngestionService $ingestion,
    ): Response {
        try {
            $result = $ingestion->ingest($request, $request->validated());
        } catch (AnalyticsEventRejected) {
            return response()->noContent(422);
        } catch (Throwable) {
            return response()->noContent();
        }

        $response = response()->noContent();
        $response->headers->setCookie($this->cookie(
            AnalyticsConfig::VISITOR_COOKIE,
            $result->visitorId,
            525_600,
        ));
        $response->headers->setCookie($this->cookie(
            AnalyticsConfig::SESSION_COOKIE,
            $result->sessionId,
            AnalyticsConfig::sessionCookieMinutes(),
        ));

        return $response;
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
