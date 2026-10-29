<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerPurchaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function order(User $buyer, string $status, string $total, string $channel = 'retail'): SalesOrder
    {
        $dealer = $channel === 'dealer' ? DealerAccount::factory()->create() : null;
        $tier = $dealer === null ? null : DealerTier::factory()->create();

        return SalesOrder::query()->create([
            'order_code' => 'ORD'.Str::upper(Str::random(16)),
            'creation_operation_key' => (string) Str::uuid(), 'creation_fingerprint' => hash('sha256', (string) Str::uuid()),
            'sales_channel' => $channel, 'order_source' => 'admin', 'buyer_user_id' => $buyer->id,
            'dealer_account_id' => $dealer?->id, 'dealer_code_snapshot' => $dealer?->code,
            'dealer_name_snapshot' => $dealer?->legal_name, 'effective_tier_id_snapshot' => $tier?->id,
            'effective_tier_code_snapshot' => $tier?->code, 'effective_tier_name_snapshot' => $tier?->name,
            'tier_source_snapshot' => $tier === null ? null : 'current',
            'warehouse_id' => Warehouse::factory()->create()->id, 'currency' => 'VND',
            'recipient_name' => $buyer->name, 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'order_status' => $status, 'pricing_context_snapshot' => $channel,
            'subtotal' => $total, 'grand_total' => $total,
            'price_resolution_fingerprint' => hash('sha256', (string) Str::uuid()), 'created_by' => $buyer->id,
        ]);
    }

    private function pay(SalesOrder $order, string $amount): Payment
    {
        $payment = Payment::factory()->create(['payment_context' => 'retail', 'amount' => $amount, 'status' => 'pending']);
        $payment->allocations()->create(['sales_order_id' => $order->id, 'allocated_amount' => $amount]);
        $payment->update(['status' => 'settled', 'settled_at' => now()]);

        return $payment;
    }

    public function test_empty_customer_keeps_appointment_details_and_zero_purchase_totals(): void
    {
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        Appointment::factory()->for($buyer)->for($customer)->create(['status' => Appointment::STATUS_COMPLETED]);

        $this->getJson("/api/admin/customers/{$customer->id}")
            ->assertOk()->assertJsonPath('data.statistics.completed_appointments', 1)
            ->assertJsonCount(1, 'data.recent_appointments');
        $this->getJson("/api/admin/customers/{$customer->id}/purchases")
            ->assertOk()->assertJsonPath('data.statistics.total_orders', 0)
            ->assertJsonPath('data.statistics.total_spent', '0.00')
            ->assertJsonCount(0, 'data.recent_orders');
    }

    public function test_guest_customer_does_not_inherit_general_vouchers_or_orders(): void
    {
        $guest = Customer::factory()->guest()->create();
        Voucher::factory()->admin()->create();

        $this->getJson("/api/admin/customers/{$guest->id}/purchases")
            ->assertOk()->assertJsonPath('data.statistics.total_orders', 0);
        $this->getJson("/api/admin/customers/{$guest->id}/purchased-products")
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/admin/customers/{$guest->id}/vouchers")
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_spend_uses_settled_payments_less_completed_refunds_and_excludes_cancelled_and_dealer_orders(): void
    {
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        $first = $this->order($buyer, 'completed', '100000.00');
        $second = $this->order($buyer, 'completed', '200000.00');
        $pending = $this->order($buyer, 'confirmed', '50000.00');
        $cancelled = $this->order($buyer, 'cancelled', '90000.00');
        $dealer = $this->order($buyer, 'completed', '500000.00', 'dealer');
        $this->pay($first, '100000.00');
        $secondPayment = $this->pay($second, '200000.00');
        $this->pay($cancelled, '90000.00');
        $refund = Refund::factory()->forOrder($second)->create(['amount' => '40000.00']);
        $refund->allocations()->create(['payment_allocation_id' => $secondPayment->allocations()->firstOrFail()->id, 'amount' => '40000.00']);
        $refund->update(['status' => 'completed', 'completed_at' => now()]);

        $this->getJson("/api/admin/customers/{$customer->id}/purchases")
            ->assertOk()->assertJsonPath('data.statistics.total_orders', 4)
            ->assertJsonPath('data.statistics.completed_orders', 2)
            ->assertJsonPath('data.statistics.processing_orders', 1)
            ->assertJsonPath('data.statistics.cancelled_orders', 1)
            ->assertJsonPath('data.statistics.total_spent', '260000.00')
            ->assertJsonPath('data.statistics.average_order_value', '130000.00')
            ->assertJsonCount(4, 'data.recent_orders');
    }

    public function test_purchased_products_aggregate_by_variant_and_subtract_completed_returns(): void
    {
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        $variant = ProductVariant::factory()->create();
        $otherVariant = ProductVariant::factory()->create(['product_id' => $variant->product_id]);
        $first = $this->order($buyer, 'completed', '50000.00');
        $second = $this->order($buyer, 'completed', '50000.00');
        $cancelled = $this->order($buyer, 'cancelled', '50000.00');
        $firstItem = $this->item($first, $variant, '2');
        $this->item($second, $variant, '3');
        $this->item($second, $otherVariant, '4');
        $this->item($cancelled, $variant, '9');
        $return = SalesReturn::factory()->forOrder($first)->create();
        $return->items()->create(['sales_order_item_id' => $firstItem->id, 'quantity' => '1', 'restock_quantity' => '1', 'non_restock_quantity' => '0', 'unit_value_snapshot' => '10000.00', 'return_value_snapshot' => '10000.00']);
        $return->update(['status' => 'completed', 'completed_at' => now()]);

        $response = $this->getJson("/api/admin/customers/{$customer->id}/purchases")
            ->assertOk()->assertJsonPath('data.purchased_products_total', 2);
        $products = collect($response->json('data.purchased_products'))->keyBy('product_variant_id');
        $this->assertEquals(4, $products[$variant->id]['total_quantity']);
        $this->assertEquals(4, $products[$otherVariant->id]['total_quantity']);
        $this->getJson("/api/admin/customers/{$customer->id}/purchased-products")
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_vouchers_show_only_available_vouchers_assigned_to_the_customer(): void
    {
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        $available = Voucher::factory()->create(['user_id' => $buyer->id]);
        Voucher::factory()->create(['user_id' => $buyer->id, 'expires_at' => now()->subDay()]);
        Voucher::factory()->create(['user_id' => $buyer->id, 'status' => Voucher::STATUS_USED]);

        $this->getJson("/api/admin/customers/{$customer->id}")
            ->assertOk()->assertJsonPath('data.statistics.available_vouchers', 1)
            ->assertJsonPath('data.available_vouchers.0.code', $available->code);
        $this->getJson("/api/admin/customers/{$customer->id}/vouchers")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $available->code);
    }

    public function test_purchase_endpoints_require_an_admin(): void
    {
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        Sanctum::actingAs($buyer);

        $this->getJson("/api/admin/customers/{$customer->id}/purchases")->assertForbidden();
        $this->getJson("/api/admin/customers/{$customer->id}/purchased-products")->assertForbidden();
        $this->getJson("/api/admin/customers/{$customer->id}/vouchers")->assertForbidden();
    }

    private function item(SalesOrder $order, ProductVariant $variant, string $quantity): SalesOrderItem
    {
        return $order->items()->create([
            'product_id' => $variant->product_id, 'product_variant_id' => $variant->id,
            'product_code_snapshot' => $variant->product->product_code, 'product_name_snapshot' => $variant->product->name,
            'sku_snapshot' => $variant->sku, 'variant_name_snapshot' => $variant->variant_name,
            'unit_code_snapshot' => 'box', 'unit_name_snapshot' => 'box', 'quantity' => $quantity,
            'pricing_context_snapshot' => 'retail', 'price_resolution_fingerprint' => hash('sha256', (string) Str::uuid()),
            'unit_price_snapshot' => '10000.00', 'base_amount' => bcmul($quantity, '10000', 2),
            'line_total' => bcmul($quantity, '10000', 2),
        ]);
    }
}
