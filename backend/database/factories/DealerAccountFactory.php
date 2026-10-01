<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DealerAccount>
 */
class DealerAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TMP-'.Str::upper(Str::random(20)),
            'legal_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '090'.fake()->numerify('#######'),
            'billing_address_line1' => fake()->streetAddress(),
            'city' => 'Ho Chi Minh City',
            'province' => 'Ho Chi Minh',
            'country' => 'VN',
            'status' => DealerAccount::STATUS_ACTIVE,
            'source_application_id' => DealerApplication::factory(),
            'created_by' => User::factory()->admin(),
            'activated_at' => now(),
        ];
    }
}
