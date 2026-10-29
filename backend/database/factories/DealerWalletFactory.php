<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealerWallet>
 */
class DealerWalletFactory extends Factory
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
            'currency' => 'VND',
            'balance' => '0.00',
        ];
    }
}
