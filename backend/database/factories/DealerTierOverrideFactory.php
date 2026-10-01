<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealerTierOverride>
 */
class DealerTierOverrideFactory extends Factory
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
            'tier_id' => DealerTier::factory(),
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'reason' => 'Commercial exception',
            'status' => DealerTierOverride::STATUS_ACTIVE,
            'created_by' => User::factory()->admin(),
        ];
    }
}
