<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory()->state(['status' => Appointment::STATUS_COMPLETED]),
            'user_id' => fn (array $attributes): int => Appointment::query()->findOrFail($attributes['appointment_id'])->user_id,
            'doctor_id' => fn (array $attributes): int => Appointment::query()->findOrFail($attributes['appointment_id'])->doctor_id,
            'service_id' => fn (array $attributes): int => Appointment::query()->findOrFail($attributes['appointment_id'])->service_id,
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional()->sentence(),
            'status' => Review::STATUS_PUBLISHED,
        ];
    }
}
