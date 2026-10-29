<?php

namespace Database\Factories;

use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'refund_code' => 'REF'.Str::upper((string) Str::ulid()),
            'currency' => 'VND',
            'amount' => '100.00',
            'refund_method' => 'cash',
            'status' => 'pending',
            'reason_code' => 'other',
            'processed_by_user_id' => User::factory()->admin(),
            'operation_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function forOrder(SalesOrder $order): static
    {
        return $this->state(fn (): array => ['sales_order_id' => $order->id, 'currency' => $order->currency]);
    }
}
