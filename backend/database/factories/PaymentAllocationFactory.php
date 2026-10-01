<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'allocated_amount' => '100.00',
        ];
    }

    public function forOrder(SalesOrder $order): static
    {
        return $this->state(fn (): array => ['sales_order_id' => $order->id]);
    }
}
