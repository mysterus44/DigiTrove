<?php

namespace Database\Factories;

use App\Enums\LifecycleStage;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->optional()->phoneNumber(),
            'country_code' => fake()->optional()->countryCode(),
            'locale' => 'fr',
            'marketing_consent' => false,
            'consent_updated_at' => null,
            'lifecycle_stage' => LifecycleStage::Lead,
            'orders_count' => 0,
            'lifetime_value_minor' => 0,
            'first_order_at' => null,
            'last_order_at' => null,
            'first_touch_source' => fake()->optional()->word(),
            'first_touch_medium' => fake()->optional()->word(),
            'first_touch_campaign' => fake()->optional()->slug(),
        ];
    }
}
