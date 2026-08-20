<?php

declare(strict_types=1);

namespace App\Payments;

use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Payments\CinetPay\CinetPayProvider;
use App\Payments\GeniusPay\GeniusPayProvider;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;

/**
 * Resolves the active payment provider fail-closed (P3-D4, D-034).
 *
 * The factory is the single decision point for which adapter is live. An empty,
 * unknown, or not-yet-implemented driver throws
 * {@see Reason::ProviderConfigurationFailure}; a selected-but-incomplete adapter
 * throws it from its own constructor. In every refusal path no HTTP request is
 * ever attempted.
 */
final class PaymentProviderFactory
{
    /** @param array<string, mixed> $config the whole `payments` config array */
    public function __construct(private readonly array $config) {}

    public function make(): PaymentProvider&PaymentConfirmationProvider
    {
        $driver = $this->config['driver'] ?? null;

        return match ($driver) {
            'cinetpay' => new CinetPayProvider($this->cinetpayConfig()),
            'geniuspay' => new GeniusPayProvider($this->providerConfig('geniuspay')),
            // Reserved but deliberately refused until the official contract exists.
            'powerpay' => throw PaymentConfirmationException::of(
                Reason::ProviderConfigurationFailure,
                'The selected payment provider is not available.',
            ),
            default => throw PaymentConfirmationException::of(
                Reason::ProviderConfigurationFailure,
                'No payment provider is configured.',
            ),
        };
    }

    /** @return array<string, mixed> */
    private function cinetpayConfig(): array
    {
        return $this->providerConfig('cinetpay');
    }

    /**
     * A provider's own config sub-array, or an empty one so its constructor — not this
     * factory — is what refuses an incomplete adapter.
     *
     * @return array<string, mixed>
     */
    private function providerConfig(string $provider): array
    {
        $config = $this->config[$provider] ?? null;

        return is_array($config) ? $config : [];
    }
}
