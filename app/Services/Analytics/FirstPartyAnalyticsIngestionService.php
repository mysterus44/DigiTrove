<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Product;
use App\Support\AnalyticsConfig;
use App\Support\AnalyticsConsent;
use App\Support\AnalyticsEventRejected;
use App\Support\AnalyticsIngestionResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class FirstPartyAnalyticsIngestionService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(Request $request, array $payload): AnalyticsIngestionResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Analytics ingestion cannot join an ambient transaction.');
        }

        if (! AnalyticsConfig::enabled() || ! AnalyticsConsent::granted($request)) {
            throw new RuntimeException('Analytics ingestion is not eligible.');
        }

        AnalyticsConfig::assertReady($request);

        $eventName = (string) $payload['event_name'];
        $entityId = isset($payload['entity_id']) ? (int) $payload['entity_id'] : null;

        if ($eventName === 'product_view' && ! Product::query()->whereKey($entityId)->exists()) {
            throw new AnalyticsEventRejected('Unknown analytics product.');
        }

        $visitorId = $this->validUuid($request->cookie(AnalyticsConfig::VISITOR_COOKIE))
            ?? (string) Str::uuid();
        $requestedSessionId = $this->validUuid($request->cookie(AnalyticsConfig::SESSION_COOKIE));
        $pagePath = $this->normalisePagePath((string) $payload['page_path']);
        $properties = (array) $payload['properties'];
        $ipHash = $this->ipHash($request);

        $row = DB::selectOne(
            <<<'SQL'
                SELECT public.ingest_first_party_analytics_event(
                    ?::uuid,
                    ?::uuid,
                    ?::bigint,
                    ?::uuid,
                    ?::varchar,
                    ?::varchar,
                    ?::bigint,
                    ?::jsonb,
                    ?::varchar,
                    ?::varchar,
                    ?::varchar,
                    ?::varchar,
                    ?::varchar,
                    ?::varchar,
                    ?::varchar,
                    ?::varchar,
                    ?::smallint,
                    ?::integer,
                    ?::integer,
                    ?::integer
                ) AS effective_session_id
                SQL,
            [
                $visitorId,
                $requestedSessionId,
                $request->user()?->getAuthIdentifier(),
                (string) Str::uuid(),
                $eventName,
                $payload['entity_type'] ?? null,
                $entityId,
                json_encode((object) $properties, JSON_THROW_ON_ERROR),
                $pagePath,
                $this->referrerHost($request),
                $this->normaliseUtm($payload['utm_source'] ?? null),
                $this->normaliseUtm($payload['utm_medium'] ?? null),
                $this->normaliseUtm($payload['utm_campaign'] ?? null),
                $this->deviceType((string) $request->userAgent()),
                null,
                $ipHash,
                $ipHash === null ? null : AnalyticsConfig::ipHashKeyVersion(),
                AnalyticsConfig::sessionTtlMinutes(),
                AnalyticsConfig::sessionMaxHours(),
                AnalyticsConfig::propertiesMaxBytes(),
            ],
        );

        $sessionId = $this->validUuid($row?->effective_session_id);
        if ($sessionId === null) {
            throw new RuntimeException('The analytics authority returned an invalid session.');
        }

        return new AnalyticsIngestionResult($visitorId, $sessionId);
    }

    private function validUuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    private function normalisePagePath(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);

        if (! is_string($path)
            || $path === ''
            || strlen($path) > 2_048
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($value, '://')
            || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($path)) === 1) {
            throw new AnalyticsEventRejected('Invalid analytics page path.');
        }

        return $path;
    }

    private function normaliseUtm(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = strtolower(trim((string) $value));

        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,254}\z/', $normalised) !== 1) {
            throw new AnalyticsEventRejected('Invalid analytics attribution.');
        }

        return $normalised;
    }

    private function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');
        if (! is_string($referrer) || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        return strlen($host) <= 253
            && preg_match('/\A[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?\z/', $host) === 1
            && ! str_contains($host, '..')
                ? $host
                : null;
    }

    private function deviceType(string $userAgent): string
    {
        return match (true) {
            $userAgent === '' => 'other',
            preg_match('/bot|crawler|spider|slurp|headless/i', $userAgent) === 1 => 'bot',
            preg_match('/ipad|tablet|kindle/i', $userAgent) === 1 => 'tablet',
            preg_match('/mobile|iphone|ipod|android/i', $userAgent) === 1 => 'mobile',
            default => 'desktop',
        };
    }

    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        $normalised = $packed === false ? false : inet_ntop($packed);

        if (! is_string($normalised)) {
            return null;
        }

        return hash_hmac('sha256', $normalised, AnalyticsConfig::ipHashKey());
    }
}
