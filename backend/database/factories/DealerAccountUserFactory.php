<?php

namespace Database\Factories;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealerAccountUser>
 */
class DealerAccountUserFactory extends Factory
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
            'user_id' => User::factory()->customer(),
            'membership_role' => DealerAccountUser::ROLE_OWNER,
            'status' => DealerAccountUser::STATUS_ACTIVE,
            'activated_at' => now(),
        ];
    }
}
