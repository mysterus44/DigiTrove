<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bounds an inbound CinetPay webhook (P3-D4, D-034).
 *
 * The provider is authenticated by HMAC inside the service, never by a user
 * session, so authorisation here is open. This request only enforces that each
 * known field is a string of bounded length; nothing here trusts the values.
 */
final class CinetPayWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $short = ['nullable', 'string', 'max:255'];

        return [
            'cpm_site_id' => $short,
            'cpm_trans_id' => ['required', 'string', 'max:255'],
            'cpm_trans_date' => ['nullable', 'string', 'max:64'],
            'cpm_amount' => ['nullable', 'string', 'max:32'],
            'cpm_currency' => ['nullable', 'string', 'max:8'],
            'signature' => $short,
            'payment_method' => ['nullable', 'string', 'max:64'],
            'cel_phone_num' => ['nullable', 'string', 'max:32'],
            'cpm_phone_prefixe' => ['nullable', 'string', 'max:8'],
            'cpm_language' => ['nullable', 'string', 'max:8'],
            'cpm_version' => ['nullable', 'string', 'max:16'],
            'cpm_payment_config' => ['nullable', 'string', 'max:32'],
            'cpm_page_action' => ['nullable', 'string', 'max:32'],
            'cpm_custom' => ['nullable', 'string', 'max:255'],
            'cpm_designation' => ['nullable', 'string', 'max:255'],
            'cpm_error_message' => ['nullable', 'string', 'max:512'],
        ];
    }
}
