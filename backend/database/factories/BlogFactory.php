<?php

namespace Database\Factories;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Blog>
 */
class BlogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_id' => User::factory()->admin(),
            'title' => fake()->unique()->sentence(6),
            'slug' => fn (array $attributes): string => Str::slug($attributes['title']),
            'category_id' => BlogCategory::factory(),
            'excerpt' => fake()->sentence(15),
            'content' => fake()->paragraphs(3, true),
            'image' => fake()->optional()->url(),
            'published_at' => now(),
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => null,
        ]);
    }
}
