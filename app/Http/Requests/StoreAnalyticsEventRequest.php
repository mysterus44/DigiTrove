<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\AnalyticsConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreAnalyticsEventRequest extends FormRequest
{
    private const TOP_LEVEL_KEYS = [
        'event_name',
        'entity_type',
        'entity_id',
        'properties',
        'page_path',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

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
            'event_name' => ['required', 'string', Rule::in(['page_view', 'product_view'])],
            'entity_type' => ['nullable', 'string'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'properties' => ['present', 'array'],
            'page_path' => ['required', 'string', 'max:4096'],
            'utm_source' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'utm_medium' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'utm_campaign' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isJson()) {
                $validator->errors()->add('payload', 'The analytics payload must be JSON.');
            }

            if ($this->query->count() !== 0) {
                $validator->errors()->add('payload', 'Analytics events cannot be supplied in the query string.');
            }

            if (array_diff(array_keys($this->all()), self::TOP_LEVEL_KEYS) !== []) {
                $validator->errors()->add('payload', 'The analytics payload contains unknown fields.');
            }

            if (strlen($this->getContent()) > AnalyticsConfig::propertiesMaxBytes() + 8_192) {
                $validator->errors()->add('payload', 'The analytics request body is too large.');
            }

            $properties = $this->input('properties');
            if (is_array($properties)) {
                $encoded = json_encode((object) $properties);
                if (! is_string($encoded) || strlen($encoded) > AnalyticsConfig::propertiesMaxBytes()) {
                    $validator->errors()->add('properties', 'The analytics properties are too large.');
                }
            }

            $eventName = $this->input('event_name');
            if ($eventName === 'page_view'
                && ($this->input('entity_type') !== null
                    || $this->input('entity_id') !== null
                    || $properties !== [])) {
                $validator->errors()->add('event_name', 'The page view payload is invalid.');
            }

            if ($eventName === 'product_view') {
                $placement = is_array($properties) ? ($properties['placement'] ?? null) : null;

                if ($this->input('entity_type') !== 'product'
                    || filter_var($this->input('entity_id'), FILTER_VALIDATE_INT) === false
                    || (int) $this->input('entity_id') < 1
                    || ! is_array($properties)
                    || array_keys($properties) !== ['placement']
                    || ! in_array($placement, ['catalog', 'search', 'recommendation', 'direct'], true)) {
                    $validator->errors()->add('event_name', 'The product view payload is invalid.');
                }
            }

            $path = (string) $this->input('page_path');
            $pathOnly = parse_url($path, PHP_URL_PATH);
            if (! is_string($pathOnly)
                || $pathOnly === ''
                || ! str_starts_with($pathOnly, '/')
                || str_starts_with($pathOnly, '//')
                || str_contains($path, '://')
                || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($path)) === 1) {
                $validator->errors()->add('page_path', 'The analytics page path is invalid.');
            }
        });
    }
}
