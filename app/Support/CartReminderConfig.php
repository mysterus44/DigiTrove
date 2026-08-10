<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * P6-C configuration boundary (D-056 §3, §13).
 *
 * NO MARKETING CADENCE IS DECIDED HERE. The inactivity window, the number of reminder
 * steps, the cooldown and the retention are operator settings with hard bounds and NO
 * default: a deployment that turns the feature on without choosing them gets a refusal,
 * not a number this codebase invented. Only the purely operational batch size has a
 * default, because it is a throughput knob and not a customer-facing decision.
 *
 * Every accessor is fail-closed: an absent or malformed value throws BEFORE any write,
 * so a bad flag can never produce a half-configured send.
 */
final class CartReminderConfig
{
    // ── Independent capability switches ──────────────────────────────────────────

    public static function detectionEnabled(): bool
    {
        return self::boolean('crm.cart_reminders.detection_enabled');
    }

    public static function enqueueEnabled(): bool
    {
        return self::boolean('crm.cart_reminders.enqueue_enabled');
    }

    public static function sendEnabled(): bool
    {
        return self::boolean('crm.cart_reminders.send_enabled');
    }

    public static function purgeEnabled(): bool
    {
        return self::boolean('crm.cart_reminders.purge_enabled');
    }

    public static function assertDetectionEnabled(): void
    {
        CrmConfig::assertEnabled();

        if (! self::detectionEnabled()) {
            throw new RuntimeException('Cart abandonment detection is disabled.');
        }
    }

    public static function assertEnqueueEnabled(): void
    {
        CrmConfig::assertEnabled();

        if (! self::enqueueEnabled()) {
            throw new RuntimeException('Cart reminder enqueue is disabled.');
        }
    }

    /**
     * The send switch is the strictest boundary in the gate: it also requires a mail
     * transport that can genuinely deliver, because a reminder carries a live capability
     * and a logging transport would write it to disk.
     */
    public static function assertSendEnabled(): void
    {
        CrmConfig::assertEnabled();

        if (! self::sendEnabled()) {
            throw new RuntimeException('Cart reminder sending is disabled.');
        }

        MailTransportGuard::assertSafe();
    }

    /**
     * The resume surface is gated on SENDING, not on a switch of its own: a capability
     * can only exist if a reminder was sent, so a separate flag would be a boundary with
     * nothing behind it. The mail transport check is deliberately NOT repeated here —
     * redemption delivers no mail.
     */
    public static function assertEnabledForResume(): void
    {
        CrmConfig::assertEnabled();

        if (! self::sendEnabled()) {
            throw new RuntimeException('Cart reminder sending is disabled.');
        }
    }

    public static function assertPurgeEnabled(): void
    {
        CrmConfig::assertEnabled();

        if (! self::purgeEnabled()) {
            throw new RuntimeException('Cart reminder purge is disabled.');
        }
    }

    // ── Bounded operator settings ────────────────────────────────────────────────

    /** How long a cart must be inactive before it counts as abandoned. 5 min .. 1 year. */
    public static function inactivityMinutes(): int
    {
        return self::requiredInteger('crm.cart_reminders.inactivity_minutes', 5, 525600);
    }

    /** Rows handled per operational batch. Throughput only, so a default is legitimate. */
    public static function batchSize(): int
    {
        return self::boundedInteger('crm.cart_reminders.batch_size', 50, 1, 100);
    }

    /** The attempt cap: how many reminder steps a single cart may ever receive. */
    public static function maxStep(): int
    {
        return self::requiredInteger('crm.cart_reminders.max_step', 1, 10);
    }

    /** Minimum delay between two reminders for the same cart. */
    public static function cooldownMinutes(): int
    {
        return self::requiredInteger('crm.cart_reminders.cooldown_minutes', 1, 525600);
    }

    /** How long a resume capability stays usable after it is sent. 5 min .. 30 days. */
    public static function capabilityTtlMinutes(): int
    {
        return self::requiredInteger('crm.cart_reminders.capability_ttl_minutes', 5, 43200);
    }

    /** How long a terminal attempt is kept before the purge may remove it. */
    public static function retentionDays(): int
    {
        return self::requiredInteger('crm.cart_reminders.retention_days', 1, 3650);
    }

    // ── Primitives ───────────────────────────────────────────────────────────────

    /**
     * A setting the operator MUST choose. There is deliberately no fallback: inventing
     * a cadence would be this codebase asserting a marketing decision it never made.
     */
    private static function requiredInteger(string $key, int $min, int $max): int
    {
        $value = config($key);

        if ($value === null || $value === '') {
            throw new RuntimeException("The setting [{$key}] must be configured explicitly.");
        }

        return self::parseBounded($value, $key, $min, $max);
    }

    private static function boundedInteger(string $key, int $default, int $min, int $max): int
    {
        $value = config($key, $default);

        if ($value === null || $value === '') {
            $value = $default;
        }

        return self::parseBounded($value, $key, $min, $max);
    }

    private static function parseBounded(mixed $value, string $key, int $min, int $max): int
    {
        // A bool is not an integer: `true` must never be read as 1.
        $valid = (is_int($value) && ! is_bool($value))
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);

        if (! $valid) {
            throw new RuntimeException("The setting [{$key}] is not a positive integer.");
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            throw new RuntimeException("The setting [{$key}] must be between {$min} and {$max}.");
        }

        return $int;
    }

    /** Anything other than a real boolean is treated as OFF. */
    private static function boolean(string $key): bool
    {
        $value = config($key, false);

        if (! is_bool($value)) {
            return false;
        }

        return $value;
    }
}
