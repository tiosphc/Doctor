<?php

namespace Database\Factories;

use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\RefundAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundAllocation>
 */
class RefundAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['amount' => '100.00'];
    }

    public function forSource(Refund $refund, PaymentAllocation $allocation): static
    {
        return $this->state(fn (): array => ['refund_id' => $refund->id,
            'payment_allocation_id' => $allocation->id]);
    }
}
