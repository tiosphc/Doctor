<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerWalletTopUpRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DealerWalletTopUpRequest>
 */
class DealerWalletTopUpRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dealer_account_id' => DealerAccount::factory(),
            'created_by_user_id' => User::factory()->customer(),
            'currency' => 'VND',
            'amount' => '100000000.00',
            'provider' => 'payos',
            'status' => 'initiating',
            'operation_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
