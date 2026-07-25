<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\AnalyticsConsent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAnalyticsConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'consent' => ['required', 'string', Rule::in([AnalyticsConsent::GRANTED, AnalyticsConsent::DENIED])],
        ];
    }
}
