<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session cookie attributes that production is not allowed to weaken (H2.2).
 *
 * ⚠️ THE DEFECT THIS CLOSES. `config/session.php` read `env('SESSION_SECURE_COOKIE')` with
 * NO default. A missing line in `.env` therefore produced `null`, which Laravel reads as
 * "not secure" — the session cookie travelled over plain HTTP in production because a
 * variable was absent, and nothing anywhere said so. A missing setting must never be the
 * quiet path to the weaker behaviour.
 *
 * The shape mirrors `payments.*.require_https` and {@see DeliveryConfig}: the environment
 * decides in `local` and `testing`, and is simply NOT CONSULTED in production. This lives
 * in a named class rather than as ternaries inside a config file for a measured reason —
 * `config/*.php` is loaded through an IMMUTABLE env repository, so logic buried there
 * cannot be exercised by a test at all. Behaviour that matters must be reachable.
 */
final class SessionCookiePolicy
{
    /** Environments where a developer legitimately runs over plain HTTP. */
    private const RELAXED_ENVIRONMENTS = ['local', 'testing'];

    /**
     * The only cross-site policies that still mitigate CSRF.
     *
     * `none` is a supported Laravel value that disables the protection outright, and `null`
     * behaves the same way in practice — neither is reachable in production.
     */
    private const ALLOWED_SAME_SITE = ['lax', 'strict'];

    public static function secure(?string $environment, mixed $configured): bool
    {
        return self::isRelaxed($environment) ? self::boolean($configured, false) : true;
    }

    /**
     * A session cookie readable from JavaScript turns any XSS into a session takeover, so
     * production never lets an environment variable disable it.
     */
    public static function httpOnly(?string $environment, mixed $configured): bool
    {
        return self::isRelaxed($environment) ? self::boolean($configured, true) : true;
    }

    /** Falls back to `lax` rather than silently dropping CSRF mitigation over a typo. */
    public static function sameSite(?string $environment, mixed $configured): ?string
    {
        $value = is_string($configured) ? strtolower(trim($configured)) : null;

        if (self::isRelaxed($environment)) {
            return $value ?? 'lax';
        }

        return in_array($value, self::ALLOWED_SAME_SITE, true) ? $value : 'lax';
    }

    private static function isRelaxed(?string $environment): bool
    {
        return in_array($environment ?? 'production', self::RELAXED_ENVIRONMENTS, true);
    }

    /** `.env` yields strings: "false" is a string, and a truthy one. */
    private static function boolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return $value === null ? $default : (bool) $value;
    }
}
