<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_code' => 'PAY'.Str::upper((string) Str::ulid()),
            'payment_context' => 'retail',
            'dealer_account_id' => null,
            'payer_user_id' => null,
            'currency' => 'VND',
            'amount' => '100.00',
            'payment_method' => 'cash',
            'status' => 'pending',
            'external_reference' => null,
            'external_reference_normalized' => null,
            'note' => null,
            'recorded_by_user_id' => User::factory()->admin(),
            'operation_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'settled_at' => null,
        ];
    }
}
