<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed accessor for webhook reconciliation (H1, dette #6).
 *
 * DISABLED by default, on the same pattern as {@see DeliveryConfig} and every CRM
 * processing flag: a misconfigured bound throws rather than degrading to a value someone
 * did not choose. A reconciliation that silently ran with a one-minute expiry would close
 * out live events; one with a thousand-hour expiry would never close anything and would
 * look like it worked.
 *
 * Every getter validates BEFORE any row is read or written — the same rule as
 * `CHECKOUT_PENDING_TTL_MINUTES` (D-032): an invalid setting fails the run, it never fails
 * halfway through it.
 */
final class WebhookReconciliationConfig
{
    private const ESCALATION_MIN = 1;

    /** A week. Beyond that an "alert" is archaeology, not an alert. */
    private const ESCALATION_MAX = 10_080;

    private const EXPIRY_MIN = 1;

    /** A year, matching the delivery TTL ceiling. */
    private const EXPIRY_MAX = 8_760;

    public static function enabled(): bool
    {
        return (bool) config('payments.reconciliation.enabled', false);
    }

    /**
     * How long an unresolved event may sit before an operator is told about it.
     *
     * This is the part that matters while the rest is not yet built: the expiry closes the
     * record, the alert is what actually reaches a human in time to do something.
     */
    public static function escalationMinutes(): int
    {
        return self::boundedInt(
            config('payments.reconciliation.escalation_minutes'),
            self::ESCALATION_MIN,
            self::ESCALATION_MAX,
            'WEBHOOK_ESCALATION_MINUTES',
        );
    }

    /** How long before an unresolved event is closed out as `unresolved_expired`. */
    public static function expiryHours(): int
    {
        return self::boundedInt(
            config('payments.reconciliation.expiry_hours'),
            self::EXPIRY_MIN,
            self::EXPIRY_MAX,
            'WEBHOOK_EXPIRY_HOURS',
        );
    }

    /**
     * Validate the whole configuration up front, including the relationship BETWEEN the two
     * values — each is individually sensible while the pair can still be nonsense.
     *
     * ⚠️ An escalation threshold at or beyond the expiry window means no event is ever
     * alerted on before it is closed: the alert would fire on rows that are already
     * terminal, i.e. never. Refusing that pair is the difference between a guard and
     * decoration.
     */
    public static function assertReady(): void
    {
        $escalation = self::escalationMinutes();
        $expiry = self::expiryHours() * 60;

        if ($escalation >= $expiry) {
            throw new RuntimeException(
                'WEBHOOK_ESCALATION_MINUTES ('.$escalation.') must be shorter than '
                .'WEBHOOK_EXPIRY_HOURS ('.self::expiryHours().'h): otherwise nothing is ever '
                .'alerted on before it is closed out.',
            );
        }
    }

    private static function boundedInt(mixed $value, int $min, int $max, string $name): int
    {
        // `.env` yields strings; a float, "12abc" or an empty value must refuse rather than
        // be coerced into something plausible.
        if (! is_int($value) && ! (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1)) {
            throw new RuntimeException($name.' must be a whole number of at least '.$min.'.');
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            throw new RuntimeException($name.' must be between '.$min.' and '.$max.'.');
        }

        return $int;
    }
}
