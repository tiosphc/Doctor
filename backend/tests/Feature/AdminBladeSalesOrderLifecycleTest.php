<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeSalesOrderLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{0: User, 1: Warehouse, 2: ProductVariant, 3: PriceListItem, 4: SalesOrder} */
    private function draft(string $quantity = '2'): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $price = PriceListItem::factory()->create([
            'price_list_id' => $list->id, 'product_variant_id' => $variant->id,
            'unit_price' => '120000.00',
        ]);
        $this->actingAs($admin)->post(route('admin.web.sales.orders.store'), [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => 'Người nhận thử',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => '123 Main',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => $quantity]],
        ])->assertRedirect();

        return [$admin, $warehouse, $variant, $price, SalesOrder::query()->sole()];
    }

    private function stock(User $admin, Warehouse $warehouse, ProductVariant $variant, string $quantity = '10'): void
    {
        app(InventoryService::class)->opening([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
            'reason_detail' => 'Opening stock for order lifecycle test',
        ], $admin->id);
    }

    public function test_guest_and_customer_cannot_mutate_order_lifecycle(): void
    {
        [, , , , $order] = $this->draft();
        auth()->logout();
        foreach (['confirm', 'reprice', 'cancel'] as $action) {
            $this->post(route('admin.web.sales.orders.'.$action, $order), [
                'operation_key' => (string) Str::uuid(), 'reason' => 'Request',
            ])->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->customer()->create());
        foreach (['confirm', 'reprice', 'cancel'] as $action) {
            $this->post(route('admin.web.sales.orders.'.$action, $order), [
                'operation_key' => (string) Str::uuid(), 'reason' => 'Request',
            ])->assertForbidden();
        }
        $this->assertSame('draft', $order->fresh()->order_status);
    }

    public function test_confirm_reserves_without_shipping_and_replay_does_not_reserve_twice(): void
    {
        [$admin, $warehouse, $variant, , $order] = $this->draft();
        $this->stock($admin, $warehouse, $variant);
        $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee('Xác nhận đơn')->assertSee('Cập nhật giá')->assertSee('Hủy đơn');
        $key = (string) Str::uuid();

        $this->post(route('admin.web.sales.orders.confirm', $order), [
            'operation_key' => $key, 'lifecycle_action' => 'confirm',
        ])->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertDatabaseHas('sales_orders', [
            'id' => $order->id, 'order_status' => 'confirmed', 'fulfillment_status' => 'reserved',
            'payment_status' => 'unpaid', 'confirmed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '2.000',
        ]);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('sales_order_histories', [
            'sales_order_id' => $order->id, 'event_type' => 'confirmed', 'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
        $detail = $this->get(route('admin.web.sales.orders.show', $order));
        $detail->assertOk()->assertSee('Đã giữ 2.000');
        $this->assertFalse($detail->viewData('lifecycleActions')['reprice']);
        $this->assertFalse($detail->viewData('lifecycleActions')['confirm']);

        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => $key])
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(1, $order->histories()->where('event_type', 'confirmed')->count());
    }

    public function test_insufficient_stock_rolls_back_confirmation_and_preserves_balances(): void
    {
        [$admin, $warehouse, $variant, , $order] = $this->draft();
        $this->stock($admin, $warehouse, $variant, '1');
        $key = (string) Str::uuid();

        $this->from(route('admin.web.sales.orders.show', $order))
            ->post(route('admin.web.sales.orders.confirm', $order), [
                'operation_key' => $key, 'lifecycle_action' => 'confirm',
            ])->assertRedirect(route('admin.web.sales.orders.show', $order))->assertSessionHasErrors('operation');
        $this->assertSame('draft', $order->fresh()->order_status);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000',
        ]);
        $this->assertDatabaseMissing('sales_order_operations', ['operation_key' => $key]);
        $this->assertSame(0, $order->histories()->where('event_type', 'confirmed')->count());
        $this->assertStringContainsString('Kho không đủ hàng', session('errors')->first('operation'));
    }

    public function test_reprice_is_explicit_retail_draft_only_and_confirm_uses_new_snapshot(): void
    {
        [$admin, $warehouse, $variant, $price, $order] = $this->draft();
        $this->stock($admin, $warehouse, $variant);
        $price->update(['unit_price' => '130000.00']);
        $this->post(route('admin.web.sales.orders.confirm', $order), [
            'operation_key' => (string) Str::uuid(), 'lifecycle_action' => 'confirm',
        ])->assertSessionHasErrors('operation');
        $this->assertSame('draft', $order->fresh()->order_status);
        $this->assertDatabaseCount('inventory_reservations', 0);

        $key = (string) Str::uuid();
        $this->post(route('admin.web.sales.orders.reprice', $order), [
            'operation_key' => $key, 'lifecycle_action' => 'reprice',
        ])->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertSame('260000.00', $order->fresh()->grand_total);
        $this->assertSame('130000.00', $order->items()->sole()->unit_price_snapshot);
        $this->assertSame('draft', $order->fresh()->order_status);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'SalesOrder', 'target_id' => $order->id, 'action' => 'REPRICE',
        ]);
        $this->assertSame(1, $order->histories()->count());
        $this->post(route('admin.web.sales.orders.reprice', $order), ['operation_key' => $key])
            ->assertRedirect(route('admin.web.sales.orders.show', $order));

        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => (string) Str::uuid()])
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->post(route('admin.web.sales.orders.reprice', $order), ['operation_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('operation');
        $this->assertSame('260000.00', $order->fresh()->grand_total);
    }

    public function test_cancel_requires_reason_and_replay_does_not_duplicate_history(): void
    {
        [, , , , $order] = $this->draft();
        $this->post(route('admin.web.sales.orders.cancel', $order), [
            'operation_key' => (string) Str::uuid(), 'reason' => ' ', 'lifecycle_action' => 'cancel',
        ])->assertSessionHasErrors('reason');
        $this->assertSame('draft', $order->fresh()->order_status);

        $key = (string) Str::uuid();
        $payload = ['operation_key' => $key, 'reason' => 'Khách đổi ý', 'lifecycle_action' => 'cancel'];
        $this->post(route('admin.web.sales.orders.cancel', $order), $payload)
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertDatabaseHas('sales_orders', [
            'id' => $order->id, 'order_status' => 'cancelled', 'cancellation_reason' => 'Khách đổi ý',
        ]);
        $this->assertSame(1, $order->histories()->where('event_type', 'cancelled')->count());
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->post(route('admin.web.sales.orders.cancel', $order), $payload)->assertRedirect();
        $this->post(route('admin.web.sales.orders.cancel', $order), [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Khác',
        ])->assertSessionHasErrors('operation');
        $this->assertSame(1, $order->histories()->where('event_type', 'cancelled')->count());
        $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertDontSee('Xác nhận đơn')->assertDontSee('Cập nhật giá')->assertDontSee('Hủy đơn');
    }

    public function test_cancel_confirmed_order_releases_reserved_stock_once_without_refund(): void
    {
        [$admin, $warehouse, $variant, , $order] = $this->draft();
        $this->stock($admin, $warehouse, $variant);
        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => (string) Str::uuid()])
            ->assertRedirect();
        $key = (string) Str::uuid();
        $payload = ['operation_key' => $key, 'reason' => 'Không nhận hàng'];
        $this->post(route('admin.web.sales.orders.cancel', $order), $payload)->assertRedirect();
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000',
        ]);
        $this->assertDatabaseHas('inventory_reservations', [
            'sales_order_id' => $order->id, 'original_quantity' => '2.000',
            'released_quantity' => '2.000', 'status' => 'released',
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->post(route('admin.web.sales.orders.cancel', $order), $payload)->assertRedirect();
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'reserved_quantity' => '0.000',
        ]);
        $this->assertSame(1, $order->histories()->where('event_type', 'cancelled')->count());
    }

    public function test_paid_confirmed_order_cannot_cancel_or_release_stock(): void
    {
        [$admin, $warehouse, $variant, , $order] = $this->draft();
        $this->stock($admin, $warehouse, $variant);
        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => (string) Str::uuid()])
            ->assertRedirect();
        app(PaymentService::class)->recordSettledPayment($order, [
            'operation_key' => (string) Str::uuid(), 'amount' => '100000.00',
            'payment_method' => 'cash',
        ], $admin->id);
        $this->get(route('admin.web.sales.orders.show', $order))->assertOk()->assertDontSee('Hủy đơn');
        $this->post(route('admin.web.sales.orders.cancel', $order), [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Đã thu tiền', 'lifecycle_action' => 'cancel',
        ])->assertSessionHasErrors('operation');
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'reserved_quantity' => '2.000',
        ]);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_dealer_draft_shows_supported_actions_but_rejects_retail_reprice(): void
    {
        [, , , , $order] = $this->draft();
        $account = DealerAccount::factory()->create();
        $tier = DealerTier::factory()->create();
        $order->update([
            'sales_channel' => 'dealer', 'order_source' => 'quick_order',
            'dealer_account_id' => $account->id, 'dealer_code_snapshot' => $account->code,
            'dealer_name_snapshot' => $account->legal_name,
            'effective_tier_id_snapshot' => $tier->id, 'effective_tier_code_snapshot' => $tier->code,
            'effective_tier_name_snapshot' => $tier->name, 'tier_source_snapshot' => 'default',
            'pricing_context_snapshot' => 'dealer',
        ]);

        $detail = $this->get(route('admin.web.sales.orders.show', $order));
        $detail->assertOk();
        $this->assertTrue($detail->viewData('lifecycleActions')['confirm']);
        $this->assertFalse($detail->viewData('lifecycleActions')['reprice']);
        $this->assertTrue($detail->viewData('lifecycleActions')['cancel']);
        $this->post(route('admin.web.sales.orders.reprice', $order), [
            'operation_key' => (string) Str::uuid(), 'lifecycle_action' => 'reprice',
        ])->assertSessionHasErrors('operation');
        $this->assertSame('draft', $order->fresh()->order_status);
        $this->assertSame('240000.00', $order->grand_total);
    }
}
