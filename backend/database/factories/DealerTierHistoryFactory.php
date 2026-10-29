<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealerTierHistory>
 */
class DealerTierHistoryFactory extends Factory
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
            'new_tier_id' => DealerTier::factory(),
            'source' => DealerTierHistory::SOURCE_INITIAL,
            'effective_at' => now(),
        ];
    }
}
