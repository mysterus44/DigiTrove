<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use App\Services\Payments\PaymentConfirmationException;

/**
 * A payment provider port for server-side confirmation (P3-D4, D-034).
 *
 * Three clearly separated responsibilities, all living inside the adapter:
 *
 *   1. authenticate an inbound webhook (HMAC), returning only true/false;
 *   2. counter-verify the payment against the provider's own records;
 *   3. normalize the provider's vendor status onto {@see NormalizedPaymentStatus}.
 *
 * The service depends on this interface only. The webhook body is never
 * authoritative — a valid signature merely authorises the *counter-call*.
 */
interface PaymentConfirmationProvider
{
    /**
     * The canonical provider name: `^[a-z0-9][a-z0-9_-]{0,31}$`, matching
     * `payments.provider`.
     */
    public function name(): string;

    /**
     * Constant-time HMAC verification of a webhook. Returns false — never
     * throws — on a missing header, a malformed body or a mismatch.
     */
    public function verifyWebhookSignature(ProviderWebhookEnvelope $webhook): bool;

    /**
     * Counter-verify the payment against the provider. Throws a sanitised
     * {@see PaymentConfirmationException} on any network,
     * protocol or configuration failure — never a raw provider detail.
     */
    public function verifyPayment(ProviderPaymentVerificationRequest $request): ProviderPaymentVerificationResult;
}
