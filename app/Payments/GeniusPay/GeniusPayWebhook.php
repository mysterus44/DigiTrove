<?php

declare(strict_types=1);

namespace App\Payments\GeniusPay;

use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Payments\CinetPay\CinetPayWebhook;

/**
 * GeniusPay-specific webhook shape knowledge (Genius Pay gate).
 *
 * The twin of {@see CinetPayWebhook}: it isolates which inbound
 * fields DigiTrove is willing to retain and hash, so no vendor field name leaks into the
 * confirmation service.
 *
 * Two things differ from CinetPay, both forced by the provider:
 *
 *  1. GeniusPay posts JSON, so the envelope carries a FLATTENED view of the decoded body
 *     ({@see self::flatten}) plus the exact raw bytes. Only the raw bytes are ever signed;
 *     the flattened view exists so the audit trail and the dedup hash keep the same
 *     `array<string, string>` shape every other provider already uses.
 *  2. GeniusPay ships a real event `id` (a UUID), so the external event id is that id —
 *     not a derived digest. CinetPay had none, which is why it derives one.
 */
final class GeniusPayWebhook
{
    /** Hard bound on any single retained value; see {@see self::scalar}. */
    private const MAX_VALUE_LENGTH = 255;

    /** The event GeniusPay emits for a refund. It never travels the confirmation path. */
    public const REFUND_EVENT = 'payment.refunded';

    /**
     * Mirrors {@see GeniusPayProvider} — the same charset, for the same reason: the
     * reference reaches both a URL path and a database lookup.
     */
    private const REFERENCE_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /**
     * Non-PII fields retained for audit and used for the dedup hash.
     *
     * ⚠️ ALLOWLIST, not a denylist: a field GeniusPay adds tomorrow is dropped by default
     * rather than silently stored. Nothing here is a customer name, phone or address, and
     * the signature headers are never part of the payload.
     */
    private const ALLOWED_FIELDS = [
        'id',
        'event',
        'type',
        'created_at',
        'timestamp',
        'data.id',
        'data.reference',
        'data.status',
        'data.amount',
        'data.currency',
        'data.payment_method',
        'data.paid_at',
        'data.refunded_at',
        'reference',
        'status',
        'amount',
        'currency',
        'payment_method',
    ];

    /**
     * Flatten a decoded JSON body to the `array<string, string>` the envelope carries.
     *
     * Depth is bounded to two levels (`data.reference`), which is every level the
     * documented payload has. Nested arrays beyond that are dropped rather than serialised:
     * an unbounded flatten is how a webhook body becomes a storage amplification vector.
     *
     * @param  array<mixed>  $body
     * @return array<string, string>
     */
    public static function flatten(array $body): array
    {
        $flat = [];

        foreach ($body as $key => $value) {
            $key = (string) $key;

            if (is_scalar($value) || $value === null) {
                $flat[$key] = self::scalar($value);

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $childKey => $childValue) {
                if (is_scalar($childValue) || $childValue === null) {
                    $flat[$key.'.'.(string) $childKey] = self::scalar($childValue);
                }
            }
        }

        return $flat;
    }

    /**
     * The provider-side event id (a UUID). Used for deduplication against the existing
     * `payment_webhook_events (provider, external_event_id)` unique index — no migration.
     */
    public static function eventId(ProviderWebhookEnvelope $envelope): ?string
    {
        $value = $envelope->param('id');

        return ($value === null || trim($value) === '') ? null : trim($value);
    }

    /**
     * The payment reference (`MTX-…`) GeniusPay returned at initiation.
     *
     * ⚠️ This is the ONLY handle GeniusPay gives back. It does not echo a merchant-supplied
     * id, so it — not `payment.public_id` — is what identifies the attempt on this provider.
     *
     * The charset is validated HERE, where the provider's format is known, rather than in
     * the confirmation service: the service must stay free of vendor shape knowledge, and a
     * value that will be matched against a database column has no business being unbounded.
     * A reference that does not match is `null`, i.e. an invalid webhook — never a lookup.
     */
    public static function reference(ProviderWebhookEnvelope $envelope): ?string
    {
        foreach (['data.reference', 'reference'] as $field) {
            $value = $envelope->param($field);
            if ($value === null) {
                continue;
            }

            $value = trim($value);
            if (preg_match(self::REFERENCE_PATTERN, $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /** The event label (`payment.completed`, `payment.refunded`, …), bounded and lowered. */
    public static function eventType(ProviderWebhookEnvelope $envelope): ?string
    {
        foreach (['event', 'type'] as $field) {
            $value = $envelope->param($field);
            if ($value !== null && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    /**
     * The allowlisted, non-PII subset actually present in the notification.
     *
     * @param  array<string, string>  $flat
     * @return array<string, string>
     */
    public static function filteredPayload(array $flat): array
    {
        $filtered = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $flat)) {
                $filtered[$field] = $flat[$field];
            }
        }

        return $filtered;
    }

    /**
     * Canonical SHA-256 over the allowlisted subset: keys sorted, stable JSON encoding.
     *
     * ⚠️ Deliberately NOT a hash of the raw body. The raw bytes are the signature's input;
     * the payload hash is a dedup key over what we actually keep, so a provider that
     * reformats its JSON without changing a single value still deduplicates.
     *
     * @param  array<string, string>  $filtered
     */
    public static function payloadHash(array $filtered): string
    {
        ksort($filtered);

        $json = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json === false ? '' : $json);
    }

    /**
     * A bounded external event id built from the provider's own event id.
     *
     * The prefix keeps it self-describing next to CinetPay's `derived:` ids and guarantees
     * the value stays inside the column bound regardless of what the provider sends.
     */
    public static function externalEventId(string $eventId): string
    {
        return 'geniuspay:'.hash('sha256', $eventId);
    }

    /**
     * Every retained value is length-bounded HERE, not only in the FormRequest.
     *
     * The request rules bound the fields we know about; this bounds whatever arrives. A
     * webhook body is attacker-influenced input, and `filtered_payload` is stored — an
     * unbounded scalar is a storage amplification vector, not a formatting detail.
     */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_substr($value === null ? '' : (string) $value, 0, self::MAX_VALUE_LENGTH);
    }
}
