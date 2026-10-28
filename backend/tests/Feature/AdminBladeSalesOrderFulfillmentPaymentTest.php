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
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeSalesOrderFulfillmentPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, Warehouse, list<ProductVariant>, SalesOrder} */
    private function order(bool $twoItems = false, string $source = 'admin', string $paymentMethod = 'cod'): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();
        $list = PriceList::factory()->create();
        $variants = [];
        foreach (range(1, $twoItems ? 2 : 1) as $index) {
            $variant = ProductVariant::factory()->create(['track_inventory' => true]);
            PriceListItem::factory()->create(['price_list_id' => $list->id,
                'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
            $variants[] = $variant;
        }
        $payload = [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'payment_method' => $paymentMethod,
            'recipient_name' => 'Người nhận thử', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '123 Main', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => array_map(fn (ProductVariant $variant): array => ['sku' => $variant->sku, 'quantity' => '4'], $variants),
        ];
        $this->actingAs($admin);
        if ($source === 'cart') {
            $order = app(SalesOrderService::class)->createDraft($payload, $admin->id, 'cart');
            $order = app(SalesOrderService::class)->submitRetail($order, (string) Str::uuid(), $admin->id);
        } else {
            $this->post(route('admin.web.sales.orders.store'), $payload)->assertRedirect();
            $order = SalesOrder::query()->sole();
        }

        return [$admin, $warehouse, $variants, $order];
    }

    /** @param list<ProductVariant> $variants */
    private function stock(User $admin, Warehouse $warehouse, array $variants): void
    {
        foreach ($variants as $variant) {
            app(InventoryService::class)->opening([
                'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
                'quantity' => '10', 'operation_key' => (string) Str::uuid(),
                'reason_detail' => 'Opening stock for fulfillment test',
            ], $admin->id);
        }
    }

    private function confirm(SalesOrder $order): void
    {
        $this->post(route('admin.web.sales.orders.confirm', $order), [
            'operation_key' => (string) Str::uuid(),
        ])->assertRedirect(route('admin.web.sales.orders.show', $order));
    }

    public function test_guest_and_customer_cannot_fulfill_advance_or_record_payment(): void
    {
        [, , , $order] = $this->order();
        auth()->logout();
        foreach (['fulfill', 'advance', 'payments.store'] as $action) {
            $this->post(route('admin.web.sales.orders.'.$action, $order), [
                'operation_key' => (string) Str::uuid(),
            ])->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->customer()->create());
        foreach (['fulfill', 'advance', 'payments.store'] as $action) {
            $this->post(route('admin.web.sales.orders.'.$action, $order), [
                'operation_key' => (string) Str::uuid(),
            ])->assertForbidden();
        }
        $this->assertSame('draft', $order->fresh()->order_status);
    }

    public function test_draft_rejects_fulfillment_and_payment(): void
    {
        [, , , $order] = $this->order();
        $detail = $this->get(route('admin.web.sales.orders.show', $order))->assertOk();
        $this->assertFalse($detail->viewData('canFulfill'));
        $this->assertFalse($detail->viewData('canRecordPayment'));
        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_partial_then_final_fulfillment_consumes_reservation_once_and_waits_for_payment(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order();
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        $itemId = $order->items()->sole()->id;
        $detail = $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee('Xuất hàng')->assertSee('Còn chờ 4.000');
        $this->assertTrue($detail->viewData('canFulfill'));
        $this->assertSame('4', $detail->viewData('fulfillSuggestions')[$itemId]);
        $first = ['operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $itemId, 'quantity' => '1']]];

        $this->post(route('admin.web.sales.orders.fulfill', $order), $first)->assertRedirect();
        $this->post(route('admin.web.sales.orders.fulfill', $order), $first)->assertRedirect();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variants[0]->id, 'on_hand_quantity' => '9.000', 'reserved_quantity' => '3.000']);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'order_status' => 'processing',
            'fulfillment_status' => 'partially_fulfilled', 'payment_status' => 'unpaid']);
        $this->assertSame(1, $order->histories()->where('event_type', 'fulfilled')->count());
        $this->assertDatabaseCount('stock_movements', 2);
        $detail = $this->get(route('admin.web.sales.orders.show', $order))->assertSee('Còn chờ 3.000');
        $this->assertSame('3', $detail->viewData('fulfillSuggestions')[$itemId]);

        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $itemId, 'quantity' => '3']],
        ])->assertRedirect();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variants[0]->id, 'on_hand_quantity' => '6.000', 'reserved_quantity' => '0.000']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_item_id' => $itemId,
            'consumed_quantity' => '4.000', 'status' => 'consumed']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALES_ORDER_SHIPMENT',
            'reference_id' => (string) $itemId, 'quantity' => '-3.000']);
        $this->assertSame('delivered', $order->fresh()->order_status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(2, $order->histories()->where('event_type', 'fulfilled')->count());
        $this->assertFalse($this->get(route('admin.web.sales.orders.show', $order))->viewData('canFulfill'));
    }

    public function test_invalid_quantity_and_second_line_failure_roll_back_whole_shipment(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order(twoItems: true);
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        $items = $order->items()->orderBy('id')->get();

        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $items[0]->id, 'quantity' => '0']],
        ])->assertSessionHasErrors('items.0.quantity');
        $key = (string) Str::uuid();
        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => $key,
            'items' => [
                ['item_id' => $items[0]->id, 'quantity' => '1'],
                ['item_id' => $items[1]->id, 'quantity' => '5'],
            ],
        ])->assertSessionHasErrors('operation');
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertDatabaseMissing('sales_order_operations', ['operation_key' => $key]);
        $this->assertSame(0, $order->histories()->where('event_type', 'fulfilled')->count());
        $this->assertDatabaseCount('stock_movements', 2);
        foreach ($variants as $variant) {
            $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id, 'on_hand_quantity' => '10.000', 'reserved_quantity' => '4.000']);
        }
    }

    public function test_partial_bank_transfer_replay_overpay_and_completion_after_fulfillment(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order(paymentMethod: 'bank_transfer');
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '0', 'payment_method' => 'bank_transfer',
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('payments', 0);
        $first = ['operation_key' => (string) Str::uuid(), 'amount' => '100.00',
            'payment_method' => 'bank_transfer', 'external_reference' => 'BANK-7C-1'];

        $this->post(route('admin.web.sales.orders.payments.store', $order), $first)->assertRedirect();
        $this->post(route('admin.web.sales.orders.payments.store', $order), $first)->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payment_allocations', ['sales_order_id' => $order->id, 'allocated_amount' => '100.00']);
        $detail = $this->get(route('admin.web.sales.orders.show', $order))->assertOk()->assertSee('BANK-7C-1');
        $this->assertSame('partially_paid', $detail->viewData('derivedPaymentStatus'));
        $this->assertSame('300.00', $detail->viewData('order')->outstanding_amount);
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            ...$first, 'operation_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            ...$first, 'amount' => '101.00',
        ])->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '300.01',
            'payment_method' => 'bank_transfer', 'external_reference' => 'BANK-7C-2',
        ])->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '300.00',
            'payment_method' => 'bank_transfer', 'external_reference' => 'BANK-7C-2',
        ])->assertRedirect();
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '1.00', 'payment_method' => 'cash',
        ])->assertSessionHasErrors('operation');

        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '4']],
        ])->assertRedirect();
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id,
            'order_status' => 'completed', 'fulfillment_status' => 'fulfilled', 'payment_status' => 'paid']);
        $this->assertSame(1, $order->histories()->where('event_type', 'completed')->count());
        $this->assertSame('400.00', bcadd((string) DB::table('payment_allocations')
            ->where('sales_order_id', $order->id)->sum('allocated_amount'), '0', 2));
    }

    public function test_cod_delivery_does_not_settle_until_admin_records_cash(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order();
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '4']],
        ])->assertRedirect();
        $this->assertSame('delivered', $order->fresh()->order_status);
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '400.00', 'payment_method' => 'cash',
        ])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->order_status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_legacy_paid_marker_is_shown_as_mismatch_and_blocks_manual_settlement(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order();
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        DB::table('sales_orders')->where('id', $order->id)->update(['payment_status' => 'paid']);

        $detail = $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee('Trạng thái thanh toán đã lưu khác số liệu');
        $this->assertSame('unpaid', $detail->viewData('derivedPaymentStatus'));
        $this->assertFalse($detail->viewData('canRecordPayment'));
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_dealer_wallet_order_is_read_only_for_manual_payment(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order();
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        $dealer = DealerAccount::factory()->create();
        $tier = DealerTier::factory()->create();
        $order->update([
            'sales_channel' => 'dealer', 'order_source' => 'quick_order',
            'dealer_account_id' => $dealer->id, 'dealer_code_snapshot' => $dealer->code,
            'dealer_name_snapshot' => $dealer->legal_name,
            'effective_tier_id_snapshot' => $tier->id, 'effective_tier_code_snapshot' => $tier->code,
            'effective_tier_name_snapshot' => $tier->name, 'tier_source_snapshot' => 'default',
            'pricing_context_snapshot' => 'dealer', 'payment_method' => 'dealer_wallet',
        ]);

        $detail = $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee('Thanh toán Ví đại lý được xử lý qua luồng ví hiện có');
        $this->assertFalse($detail->viewData('canRecordPayment'));
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cart_order_uses_existing_preparing_shipping_delivered_flow(): void
    {
        [$admin, $warehouse, $variants, $order] = $this->order(source: 'cart');
        $this->stock($admin, $warehouse, $variants);
        $this->confirm($order);
        $this->assertFalse($this->get(route('admin.web.sales.orders.show', $order))->viewData('canFulfill'));
        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.orders.advance', $order), [
            'operation_key' => (string) Str::uuid(), 'target' => 'completed',
        ])->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.orders.advance', $order), [
            'operation_key' => (string) Str::uuid(), 'target' => 'preparing',
        ])->assertRedirect();
        $shipping = ['operation_key' => (string) Str::uuid(), 'target' => 'shipping'];
        $this->post(route('admin.web.sales.orders.advance', $order), $shipping)->assertRedirect();
        $this->post(route('admin.web.sales.orders.advance', $order), $shipping)->assertRedirect();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variants[0]->id, 'on_hand_quantity' => '6.000', 'reserved_quantity' => '0.000']);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame('shipping', $order->fresh()->order_status);
        $this->post(route('admin.web.sales.orders.advance', $order), [
            'operation_key' => (string) Str::uuid(), 'target' => 'delivered',
        ])->assertRedirect();
        $this->assertSame('delivered', $order->fresh()->order_status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '400.00', 'payment_method' => 'cash',
        ])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->order_status);
    }
}
