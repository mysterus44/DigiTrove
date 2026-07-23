<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Closed set of reasons a payment initiation can be refused (P3-D3, D-033).
 *
 * `OrderUnavailable` covers "no such order" AND "not your order": a distinct
 * reason would turn `orders.public_id` into an enumeration oracle.
 */
enum PaymentInitiationRefusalReason: string
{
    case OrderUnavailable = 'order_unavailable';
    case OrderNotPayable = 'order_not_payable';
    case OrderExpired = 'order_expired';
    case FreeOrder = 'free_order';
    case InvalidIdempotencyKey = 'invalid_idempotency_key';
    case InvalidProvider = 'invalid_provider';
    case PaymentAlreadyInProgress = 'payment_already_in_progress';
    case IdempotencyConflict = 'idempotency_conflict';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderProtocolFailure = 'provider_protocol_failure';
    case ProviderReferenceConflict = 'provider_reference_conflict';
    case IntegrityFailure = 'integrity_failure';
}
