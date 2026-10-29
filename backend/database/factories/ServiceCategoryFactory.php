<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceCategory> */
class ServiceCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'short_description' => fake()->optional()->sentence(),
            'hero_image' => null,
        ];
    }
}
