<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Delivery\DownloadAuthorizationService;
use App\Support\DeliveryConfig;
use App\Support\DownloadAccessDenied;
use App\Support\DownloadRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

final class DownloadAuthorizationController
{
    public function __invoke(
        Request $request,
        string $grantPublicId,
        DownloadAuthorizationService $authorization,
    ): JsonResponse {
        if (! DeliveryConfig::enabled()
            || collect(['token', 'attempt', 'grant'])->contains(
                static fn (string $key): bool => $request->query->has($key),
            )) {
            return $this->denied();
        }

        $header = $request->header('Authorization');
        if (! is_string($header) || preg_match('/\ABearer ([A-Za-z0-9_-]{43})\z/', $header, $matches) !== 1) {
            hash('sha256', is_string($header) ? $header : '');

            return $this->denied();
        }

        try {
            $attempt = $authorization->authorize(
                $grantPublicId,
                $matches[1],
                DownloadRequestContext::fromRequest($request),
            );
        } catch (DownloadAccessDenied) {
            return $this->denied();
        } catch (Throwable) {
            return $this->denied();
        }

        $path = "/downloads/{$grantPublicId}/file";
        $cookie = Cookie::create('dl_attempt')
            ->withValue($attempt->rawAttemptToken)
            ->withExpires($attempt->expiresAt)
            ->withPath($path)
            ->withSecure(DeliveryConfig::cookieSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);

        return response()->json(['download_url' => $path])
            ->withCookie($cookie)
            ->withHeaders($this->securityHeaders());
    }

    private function denied(): JsonResponse
    {
        return response()->json(['message' => 'Download unavailable.'], 404)
            ->withHeaders($this->securityHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
