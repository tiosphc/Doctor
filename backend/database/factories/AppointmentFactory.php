<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'doctor_id' => Doctor::factory(),
            'service_id' => Service::factory(),
            'booking_code' => 'JUN-'.now()->format('Ymd-His-v').'-'.fake()->unique()->regexify('[A-Z0-9]{2}'),
            'appointment_date' => fake()->dateTimeBetween('+1 day', '+2 months'),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => Appointment::STATUS_PENDING,
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function guest(): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'guest_name' => fake()->name(),
            'guest_email' => Str::lower(fake()->unique()->safeEmail()),
            'guest_phone' => fake()->numerify('09########'),
        ]);
    }
}
