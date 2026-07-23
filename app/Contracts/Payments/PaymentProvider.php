<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

/**
 * A payment provider port (P3-D3, D-033).
 *
 * P3-D3 ships NO real adapter: there is no HTTP call, no SDK and no secret in
 * this gate. The service depends on this interface only; tests inject a
 * deterministic fake.
 *
 * Contract for any future adapter: `initiate()` must be idempotent on
 * `ProviderInitiationRequest::paymentPublicId` — replaying the same public id
 * must not create a second charge on the provider side.
 */
interface PaymentProvider
{
    /**
     * The canonical provider name: `^[a-z0-9][a-z0-9_-]{0,31}$`, matching
     * `payments.provider`. It comes from the adapter, never from a caller.
     */
    public function name(): string;

    /**
     * Initiate a payment attempt from a server-side snapshot.
     *
     * @throws \Throwable when the provider is unreachable or errors; the service
     *                    translates any throwable here into a sanitised refusal.
     */
    public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult;
}
