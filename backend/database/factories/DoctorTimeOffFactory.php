<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\DoctorTimeOff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorTimeOff> */
class DoctorTimeOffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'date' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'start_time' => null,
            'end_time' => null,
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
