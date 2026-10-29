<?php

namespace Database\Factories;

use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('RTL####'),
            'name' => fake()->words(2, true),
            'pricing_context' => 'retail',
            'scope_type' => 'all',
            'currency' => 'VND',
            'status' => 'active',
        ];
    }
}
