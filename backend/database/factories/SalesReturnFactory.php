<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalesReturn>
 */
class SalesReturnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_code' => 'RET'.Str::upper((string) Str::ulid()),
            'status' => 'pending',
            'reason' => 'Test return',
            'processed_by_user_id' => User::factory()->admin(),
            'operation_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function forOrder(SalesOrder $order): static
    {
        return $this->state(fn (): array => ['sales_order_id' => $order->id,
            'warehouse_id' => $order->warehouse_id]);
    }
}
