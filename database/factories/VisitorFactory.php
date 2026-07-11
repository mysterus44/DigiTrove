<?php

namespace Database\Factories;

use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'first_touch_source' => fake()->optional()->word(),
            'first_touch_medium' => fake()->optional()->word(),
            'first_touch_campaign' => fake()->optional()->slug(),
            'first_landing_page' => fake()->optional()->url(),
            'country_code' => fake()->optional()->countryCode(),
        ];
    }
}
