<?php

namespace Database\Factories;

use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesReturnItem>
 */
class SalesReturnItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['quantity' => '1.000', 'restock_quantity' => '1.000',
            'non_restock_quantity' => '0.000', 'unit_value_snapshot' => '100.00'];
    }

    public function forReturnItem(SalesReturn $salesReturn, SalesOrderItem $orderItem): static
    {
        return $this->state(fn (): array => ['sales_return_id' => $salesReturn->id,
            'sales_order_item_id' => $orderItem->id, 'unit_value_snapshot' => $orderItem->unit_price_snapshot]);
    }
}
