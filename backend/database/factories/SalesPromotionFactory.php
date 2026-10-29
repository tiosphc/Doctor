<?php

namespace Database\Factories;

use App\Models\SalesPromotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesPromotion>
 */
class SalesPromotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'PROMO'.fake()->unique()->numberBetween(100000, 999999);

        return ['code' => $code, 'normalized_code' => $code, 'name' => 'Test promotion',
            'discount_type' => 'percentage', 'discount_value' => '10.00',
            'minimum_order_amount' => '0.00', 'sales_scope' => 'both', 'status' => 'active',
            'created_by_user_id' => User::factory()->admin()];
    }
}
