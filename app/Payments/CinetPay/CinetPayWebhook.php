<?php

declare(strict_types=1);

namespace App\Payments\CinetPay;

use App\Contracts\Payments\ProviderWebhookEnvelope;

/**
 * CinetPay-specific webhook shape knowledge (P3-D4, D-034).
 *
 * Isolates which inbound fields DigiTrove is willing to retain and hash. The
 * allowlist is deliberately non-PII: the phone number (`cel_phone_num`,
 * `cpm_phone_prefixe`) and the free-form `cpm_custom` are excluded from both the
 * stored `filtered_payload` and the `payload_hash`. The `x-token` header and any
 * secret are never part of either.
 */
final class CinetPayWebhook
{
    /** The provider-side transaction id equals `payment.public_id`. */
    public const TRANSACTION_ID_FIELD = 'cpm_trans_id';

    /**
     * Non-PII fields retained for audit and used for the dedup hash. Their
     * presence/values fully identify a distinct notification without storing
     * personal data.
     */
    private const ALLOWED_FIELDS = [
        'cpm_site_id',
        'cpm_trans_id',
        'cpm_trans_date',
        'cpm_amount',
        'cpm_currency',
        'signature',
        'payment_method',
        'cpm_payment_config',
        'cpm_page_action',
        'cpm_error_message',
        'cpm_version',
        'cpm_language',
        'cpm_designation',
    ];

    public static function transactionId(ProviderWebhookEnvelope $envelope): ?string
    {
        $value = $envelope->param(self::TRANSACTION_ID_FIELD);

        return ($value === null || trim($value) === '') ? null : $value;
    }

    /**
     * The allowlisted, non-PII subset actually present in the notification.
     *
     * @return array<string, string>
     */
    public static function filteredPayload(ProviderWebhookEnvelope $envelope): array
    {
        $filtered = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            $value = $envelope->param($field);
            if ($value !== null) {
                $filtered[$field] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Canonical SHA-256 over the allowlisted subset: keys sorted, stable JSON
     * encoding. Excludes the `x-token` and every secret by construction.
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
     * A deterministic, bounded event id (CinetPay ships no standalone one).
     * Same exact notification ⇒ same id; any change ⇒ a distinct event. No
     * secret, no PII, and no local financial meaning is derivable from it.
     */
    public static function externalEventId(string $transactionId, string $payloadHash): string
    {
        return 'derived:'.hash('sha256', "cinetpay\n".$transactionId."\n".$payloadHash);
    }
}
