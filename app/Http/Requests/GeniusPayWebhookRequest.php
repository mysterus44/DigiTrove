<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bounds an inbound GeniusPay webhook (Genius Pay gate).
 *
 * The provider is authenticated by HMAC inside the service, never by a user session, so
 * authorisation here is open. This request only enforces shape and bounds; nothing here
 * trusts a value.
 *
 * ⚠️ IT NEVER TOUCHES THE RAW BODY. Laravel's validated/decoded view is used for shape
 * checks only — the signature is computed against `$request->getContent()`, the exact bytes
 * that arrived. Validating a decoded body and signing a re-encoded one would verify a
 * document nobody sent.
 */
final class GeniusPayWebhookRequest extends FormRequest
{
    /**
     * The largest body this endpoint will consider.
     *
     * A signature check over an unbounded body is a cheap way to burn CPU without ever
     * holding a valid secret: the size gate runs before the HMAC.
     */
    public const MAX_BODY_BYTES = 65536;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // The provider's own event id — the deduplication key. Required.
            'id' => ['required', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'created_at' => ['nullable', 'string', 'max:64'],
            'timestamp' => ['nullable', 'string', 'max:64'],

            // The payment object, whether nested under `data` or returned flat.
            'data' => ['nullable', 'array'],
            'data.id' => ['nullable', 'string', 'max:255'],
            'data.reference' => ['nullable', 'string', 'max:64'],
            'data.status' => ['nullable', 'string', 'max:32'],
            'data.amount' => ['nullable'],
            'data.currency' => ['nullable', 'string', 'max:8'],
            'data.payment_method' => ['nullable', 'string', 'max:64'],
            'data.paid_at' => ['nullable', 'string', 'max:64'],
            'data.refunded_at' => ['nullable', 'string', 'max:64'],

            'reference' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'amount' => ['nullable'],
            'currency' => ['nullable', 'string', 'max:8'],
            'payment_method' => ['nullable', 'string', 'max:64'],
        ];
    }
}
