<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => fake()->unique()->bothify('PRD######'),
            'name' => fake()->words(3, true),
            'slug' => Str::slug(fake()->unique()->words(4, true)),
            'product_category_id' => ProductCategory::factory(),
            'status' => 'active',
        ];
    }
}
