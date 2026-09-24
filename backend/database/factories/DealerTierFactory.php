<?php

namespace Database\Factories;

use App\Models\DealerTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealerTier>
 */
class DealerTierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => strtoupper(fake()->unique()->bothify('TIER-####')), 'name' => fake()->unique()->word(), 'status' => 'active'];
    }
}
