<?php

namespace Database\Factories;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'unit_price' => '100000.00',
            'minimum_quantity' => '1.000',
        ];
    }
}
