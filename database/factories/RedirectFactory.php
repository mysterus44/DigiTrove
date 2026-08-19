<?php

namespace Database\Factories;

use App\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    public function definition(): array
    {
        $token = fake()->unique()->numberBetween(1000, 999999);

        return [
            'from_path' => '/legacy-'.$token,
            'to_path' => '/blog/cible-'.$token,
            'status_code' => 301,
        ];
    }
}
