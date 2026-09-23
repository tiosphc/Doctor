<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'RVW-'.strtoupper(fake()->unique()->bothify('####-####')),
            'user_id' => User::factory(),
            'type' => Voucher::TYPE_PERCENTAGE,
            'value' => 5,
            'source' => Voucher::SOURCE_REVIEW_REWARD,
            'source_id' => Appointment::factory(),
            'status' => Voucher::STATUS_ACTIVE,
            'expires_at' => now()->addDays(30),
            'used_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'code' => 'JUN-'.strtoupper(fake()->unique()->bothify('######')),
            'user_id' => null,
            'source' => Voucher::SOURCE_ADMIN,
            'source_id' => null,
        ]);
    }
}
