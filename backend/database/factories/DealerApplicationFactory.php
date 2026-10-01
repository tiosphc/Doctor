<?php

namespace Database\Factories;

use App\Models\DealerApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealerApplication>
 */
class DealerApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->customer(),
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '090'.fake()->numerify('#######'),
            'business_address_line1' => fake()->streetAddress(),
            'city' => 'Ho Chi Minh City',
            'province' => 'Ho Chi Minh',
            'country' => 'VN',
            'status' => DealerApplication::STATUS_PENDING,
        ];
    }
}
