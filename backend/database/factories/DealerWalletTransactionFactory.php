<?php

namespace Database\Factories;

use App\Models\DealerWallet;
use App\Models\DealerWalletDeposit;
use App\Models\DealerWalletTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DealerWalletTransaction>
 */
class DealerWalletTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dealer_wallet_id' => DealerWallet::factory(),
            'transaction_code' => 'WTX'.Str::upper((string) Str::ulid()),
            'direction' => 'credit',
            'type' => 'deposit_credit',
            'amount' => '100.00',
            'currency' => 'VND',
            'balance_before' => '0.00',
            'balance_after' => '100.00',
            'dealer_wallet_deposit_id' => DealerWalletDeposit::factory(),
            'sales_order_id' => null,
            'payment_id' => null,
            'refund_id' => null,
            'actor_user_id' => User::factory()->admin(),
            'operation_key' => (string) Str::uuid(),
        ];
    }

    public function forDeposit(DealerWalletDeposit $deposit): static
    {
        return $this->state(fn (): array => [
            'dealer_wallet_id' => $deposit->dealer_wallet_id,
            'currency' => $deposit->currency,
            'dealer_wallet_deposit_id' => $deposit->id,
        ]);
    }
}
