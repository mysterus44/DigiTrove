<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use JsonException;

final class AnalyticsConsent
{
    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    /**
     * @return array{consent: 'granted'|'denied'|'unset', version: int}
     */
    public static function state(Request $request): array
    {
        $version = AnalyticsConfig::consentVersion();
        $raw = $request->cookie(AnalyticsConfig::CONSENT_COOKIE);

        if (! is_string($raw) || $raw === '') {
            return ['consent' => 'unset', 'version' => $version];
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['consent' => 'unset', 'version' => $version];
        }

        if (! is_array($decoded)
            || array_keys($decoded) !== ['consent', 'version']
            || ! in_array($decoded['consent'] ?? null, [self::GRANTED, self::DENIED], true)
            || ($decoded['version'] ?? null) !== $version) {
            return ['consent' => 'unset', 'version' => $version];
        }

        return [
            'consent' => $decoded['consent'],
            'version' => $version,
        ];
    }

    public static function granted(Request $request): bool
    {
        return self::state($request)['consent'] === self::GRANTED;
    }

    public static function encode(string $consent): string
    {
        if (! in_array($consent, [self::GRANTED, self::DENIED], true)) {
            throw new \InvalidArgumentException('Unknown analytics consent state.');
        }

        return json_encode([
            'consent' => $consent,
            'version' => AnalyticsConfig::consentVersion(),
        ], JSON_THROW_ON_ERROR);
    }
}
