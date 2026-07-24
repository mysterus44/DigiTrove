<?php

namespace Database\Factories;

use App\Models\AnalyticsSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnalyticsSession>
 */
class AnalyticsSessionFactory extends Factory
{
    public function definition(): array
    {
        $startedAt = now()->subMinutes(5);
        $createdAt = $startedAt->addMinutes(3);

        return [
            'id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
            'user_id' => null,
            'started_at' => $startedAt,
            'last_seen_at' => $startedAt->addMinutes(2),
            'ended_at' => null,
            'entry_path' => '/catalog',
            'exit_path' => null,
            'page_views' => 1,
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'launch_2026',
            'device_type' => 'mobile',
            'country_code' => 'CI',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
