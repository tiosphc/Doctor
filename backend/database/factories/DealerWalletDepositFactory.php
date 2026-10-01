<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerWallet;
use App\Models\DealerWalletDeposit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DealerWalletDeposit>
 */
class DealerWalletDepositFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deposit_code' => 'WDP'.Str::upper((string) Str::ulid()),
            'dealer_wallet_id' => DealerWallet::factory(),
            'dealer_account_id' => DealerAccount::factory(),
            'currency' => 'VND',
            'amount' => '100.00',
            'method' => 'other_manual',
            'external_reference' => null,
            'external_reference_normalized' => null,
            'note' => null,
            'status' => 'completed',
            'recorded_by_user_id' => User::factory()->admin(),
            'operation_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'completed_at' => now(),
        ];
    }

    public function forWallet(DealerWallet $wallet): static
    {
        return $this->state(fn (): array => [
            'dealer_wallet_id' => $wallet->id,
            'dealer_account_id' => $wallet->dealer_account_id,
            'currency' => $wallet->currency,
        ]);
    }
}
