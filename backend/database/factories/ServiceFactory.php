<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => ServiceCategory::factory(),
            'name' => fake()->unique()->words(3, true),
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']) ?: fake()->unique()->slug(),
            'description' => fake()->optional()->sentence(),
            'short_description' => null,
            'duration' => fake()->randomElement([30, 45, 60]),
            'duration_note' => null,
            'price' => fake()->randomElement(['300000.00', '500000.00', '750000.00']),
            'price_note' => null,
            'image' => null,
            'hero_image' => null,
            'hero_disclaimer' => null,
            'cta_label' => null,
            'status' => Service::STATUS_ACTIVE,
            'sort_order' => 0,
            'seo_title' => null,
            'seo_description' => null,
            'content' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Service::STATUS_INACTIVE,
        ]);
    }
}
