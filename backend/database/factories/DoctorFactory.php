<?php

namespace Database\Factories;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Doctor> */
class DoctorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'specialty' => fake()->randomElement([
                'Aesthetic Medicine',
                'Dermatology',
                'Cosmetic Consultation',
            ]),
            'bio' => fake()->optional()->paragraph(),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'avatar' => null,
            'status' => Doctor::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Doctor::STATUS_INACTIVE,
        ]);
    }
}
