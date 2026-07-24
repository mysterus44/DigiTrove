<?php

namespace Database\Factories;

use App\Models\AnalyticsEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnalyticsEvent>
 */
class AnalyticsEventFactory extends Factory
{
    public function definition(): array
    {
        $occurredAt = now()->subSecond();

        return [
            'public_id' => (string) Str::uuid(),
            'occurred_at' => $occurredAt,
            'visitor_id' => (string) Str::uuid(),
            'user_id' => null,
            'session_id' => (string) Str::uuid(),
            'event_name' => 'page_view',
            'entity_type' => null,
            'entity_id' => null,
            'properties' => ['placement' => 'catalog'],
            'page_path' => '/catalog',
            'referrer_host' => 'example.test',
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'launch_2026',
            'device_type' => 'desktop',
            'country_code' => 'CI',
            'ip_hash' => hash('sha256', (string) Str::uuid()),
            'ip_hash_key_version' => 1,
            'created_at' => $occurredAt->addSecond(),
        ];
    }
}
