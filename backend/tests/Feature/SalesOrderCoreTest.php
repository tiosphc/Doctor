<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReconciliationService;
use App\Services\ReservationReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesOrderCoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array{0: Warehouse, 1: ProductVariant, 2: PriceListItem} */
    private function catalog(): array
    {
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $price = PriceListItem::factory()->create([
            'price_list_id' => $list->id, 'product_variant_id' => $variant->id,
            'unit_price' => '120000.00',
        ]);

        return [$warehouse, $variant, $price];
    }

    /** @return array<string, mixed> */
    private function body(Warehouse $warehouse, ProductVariant $variant, string $quantity = '2'): array
    {
        return [
            'operation_key' => (string) Str::uuid(),
            'sales_channel' => 'retail',
            'buyer_user_id' => User::factory()->customer()->create()->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'VND',
            'recipient_name' => 'Recipient Separate',
            'recipient_phone' => '0900000000',
            'recipient_email' => 'recipient@example.com',
            'shipping_address_line1' => '123 Main Street',
            'shipping_city' => 'HCM',
            'shipping_province' => 'HCM',
            'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => $quantity]],
        ];
    }

    private function stock(Warehouse $warehouse, ProductVariant $variant, string $quantity = '10'): void
    {
        $this->postJson('/api/admin/inventory/opening-stock', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
            'reason_detail' => 'Verified starting stock',
        ])->assertOk();
    }

    public function test_draft_snapshots_retail_price_and_recipient_without_reservation_and_replays_creation(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $body = $this->body($warehouse, $variant);
        $first = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()
            ->assertJsonPath('data.order_status', 'draft')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.items.0.unit_price_snapshot', '120000.00')
            ->assertJsonPath('data.grand_total', '240000.00');
        $id = $first->json('data.id');
        $this->assertSame('ORD'.str_pad((string) $id, 8, '0', STR_PAD_LEFT), $first->json('data.order_code'));
        $this->assertNotSame($body['buyer_user_id'], $first->json('data.recipient_name'));
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->postJson('/api/admin/sales-orders', $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/admin/sales-orders', [...$body, 'recipient_name' => 'Different'])->assertConflict()
            ->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this->assertDatabaseCount('sales_orders', 1);
    }

    public function test_confirm_reserves_then_partial_and_final_fulfillment_write_signed_shipments(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $this->stock($warehouse, $variant);
        $id = $this->postJson('/api/admin/sales-orders', $this->body($warehouse, $variant, '4'))
            ->assertCreated()->json('data.id');
        $itemId = $this->getJson("/api/admin/sales-orders/{$id}")->json('data.items.0.id');
        $key = (string) Str::uuid();
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => $key])
            ->assertOk()->assertJsonPath('data.fulfillment_status', 'reserved');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => $key])->assertOk();
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '10.000', 'reserved_quantity' => '4.000']);
        $firstShip = ['operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '1']]];
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", $firstShip)
            ->assertOk()->assertJsonPath('data.order_status', 'processing');
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", $firstShip)->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '9.000', 'reserved_quantity' => '3.000']);
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Too late',
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '3']],
        ])->assertOk()->assertJsonPath('data.order_status', 'delivered')
            ->assertJsonPath('data.fulfillment_status', 'fulfilled')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '480000', 'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.summary.payment_status', 'paid');
        $this->getJson("/api/admin/sales-orders/{$id}")
            ->assertOk()->assertJsonPath('data.order_status', 'completed');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '6.000', 'reserved_quantity' => '0.000']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALES_ORDER_SHIPMENT',
            'product_variant_id' => $variant->id, 'quantity' => '-3.000']);
        $this->assertSame(0, app(ReservationReconciliationService::class)->report()['difference_count']);
        $this->assertSame(0, app(InventoryReconciliationService::class)->report()['mismatched']);
    }

    public function test_price_change_requires_explicit_reprice_before_confirm(): void
    {
        $this->admin();
        [$warehouse, $variant, $price] = $this->catalog();
        $this->stock($warehouse, $variant);
        $id = $this->postJson('/api/admin/sales-orders', $this->body($warehouse, $variant))->assertCreated()->json('data.id');
        $price->update(['unit_price' => '130000.00']);
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'ORDER_PRICE_CHANGED');
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->postJson("/api/admin/sales-orders/{$id}/reprice", ['operation_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.grand_total', '260000.00');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.order_status', 'confirmed');
    }

    public function test_insufficient_stock_does_not_partially_reserve_multi_item_order(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $other = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['product_variant_id' => $other->id]);
        $this->stock($warehouse, $variant, '5');
        $this->stock($warehouse, $other, '1');
        $body = $this->body($warehouse, $variant);
        $body['items'][] = ['sku' => $other->sku, 'quantity' => '2'];
        $id = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'reserved_quantity' => '0.000']);
    }

    public function test_cancel_draft_and_confirmed_release_without_restoring_on_hand(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $this->stock($warehouse, $variant);
        $body = $this->body($warehouse, $variant);
        $draft = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$draft}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Wrong address',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        $body['operation_key'] = (string) Str::uuid();
        $confirmed = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$confirmed}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$confirmed}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer requested',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000']);
        $this->assertSame(0, app(ReservationReconciliationService::class)->report()['difference_count']);
    }

    public function test_validation_authorization_and_no_generic_mutation(): void
    {
        [$warehouse, $variant] = $this->catalog();
        $body = $this->body($warehouse, $variant);
        $this->postJson('/api/admin/sales-orders', $body)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson('/api/admin/sales-orders', $body)->assertForbidden();
        $this->admin();
        $this->postJson('/api/admin/sales-orders', [...$body, 'unit_price' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->postJson('/api/admin/sales-orders', [...$body, 'buyer_user_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('buyer_user_id');
        $this->postJson('/api/admin/sales-orders', [...$body, 'items' => [$body['items'][0], $body['items'][0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.sku');
        $this->postJson('/api/admin/sales-orders', [...$body, 'sales_channel' => 'dealer'])
            ->assertConflict()->assertJsonPath('code', 'UNSUPPORTED_CHANNEL');
        $id = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/sales-orders/{$id}", ['order_status' => 'completed'])->assertStatus(405);
        $this->getJson('/api/admin/sales-orders?order_status=draft')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_unavailable_catalog_warehouse_and_unit_precision_are_rejected(): void
    {
        $this->admin();
        [$warehouse, $variant, $price] = $this->catalog();
        $body = $this->body($warehouse, $variant);
        $price->update(['status' => 'inactive']);
        $this->postJson('/api/admin/sales-orders', $body)->assertConflict()->assertJsonPath('code', 'PRICE_NOT_FOUND');
        $price->update(['status' => 'active']);
        $variant->update(['sellable_retail' => false]);
        $this->postJson('/api/admin/sales-orders', $body)->assertConflict()->assertJsonPath('code', 'SKU_UNAVAILABLE');
        $variant->update(['sellable_retail' => true, 'status' => 'inactive']);
        $this->postJson('/api/admin/sales-orders', $body)->assertConflict()->assertJsonPath('code', 'SKU_UNAVAILABLE');
        $variant->update(['status' => 'active']);
        $variant->product->update(['status' => 'inactive']);
        $this->postJson('/api/admin/sales-orders', $body)->assertConflict()->assertJsonPath('code', 'SKU_UNAVAILABLE');
        $variant->product->update(['status' => 'active']);
        $warehouse->update(['status' => 'inactive']);
        $this->postJson('/api/admin/sales-orders', $body)->assertConflict()->assertJsonPath('code', 'WAREHOUSE_INACTIVE');
        $warehouse->update(['status' => 'active']);
        $body['items'][0]['quantity'] = '1.5';
        $this->postJson('/api/admin/sales-orders', $body)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_operation_keys_status_guards_and_order_code_immutability(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $this->stock($warehouse, $variant);
        $first = $this->postJson('/api/admin/sales-orders', $this->body($warehouse, $variant))->assertCreated()->json('data.id');
        $second = $this->postJson('/api/admin/sales-orders', $this->body($warehouse, $variant))->assertCreated()->json('data.id');
        $key = (string) Str::uuid();
        $this->postJson("/api/admin/sales-orders/{$first}/confirm", ['operation_key' => $key])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$second}/confirm", ['operation_key' => $key])
            ->assertConflict()->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this->postJson("/api/admin/sales-orders/{$first}/reprice", ['operation_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->expectException(QueryException::class);
        DB::table('sales_orders')->where('id', $first)->update(['order_code' => 'CHANGED']);
    }

    public function test_reservation_reconciliation_detects_balance_drift_without_repair(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $this->stock($warehouse, $variant);
        $id = $this->postJson('/api/admin/sales-orders', $this->body($warehouse, $variant))->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)->update(['reserved_quantity' => '1.000']);
        $report = $this->getJson('/api/admin/sales-orders/reservation-reconciliation')->assertOk()->json('data');
        $this->assertSame(1, $report['difference_count']);
        $this->assertSame('-1.000', $report['rows'][0]['difference']);
    }

    public function test_dealer_price_cannot_replace_missing_retail_price(): void
    {
        $this->admin();
        [$warehouse, $variant, $price] = $this->catalog();
        $price->update(['status' => 'inactive']);
        $tier = DealerTier::factory()->create();
        $dealerList = PriceList::factory()->create([
            'code' => 'DEALER-ONLY', 'pricing_context' => 'dealer',
            'scope_type' => 'tier', 'dealer_tier_id' => $tier->id,
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $dealerList->id, 'product_variant_id' => $variant->id,
            'unit_price' => '100.00',
        ]);
        $this->postJson('/api/admin/sales-orders', $this->body($warehouse, $variant))
            ->assertConflict()->assertJsonPath('code', 'PRICE_NOT_FOUND');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_retail_preview_uses_current_price_and_selected_warehouse_stock(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $otherWarehouse = Warehouse::factory()->create();
        $this->stock($warehouse, $variant, '5');
        $this->stock($otherWarehouse, $variant, '1');

        $payload = ['warehouse_id' => $warehouse->id, 'items' => [
            ['sku' => $variant->sku, 'quantity' => 2],
        ]];
        $this->getJson('/api/admin/retail-prices?status=active&warehouse_id='.$warehouse->id.'&search='.$variant->sku)
            ->assertOk()->assertJsonPath('data.0.sku', $variant->sku)
            ->assertJsonPath('data.0.available_quantity', '5.000')
            ->assertJsonPath('data.0.unit_price', '120000.00');
        $this->getJson('/api/admin/retail-prices?status=active&warehouse_id='.$warehouse->id.'&search='.urlencode($variant->product->name))
            ->assertOk()->assertJsonPath('data.0.sku', $variant->sku);
        $this->postJson('/api/admin/sales-orders/retail-preview', $payload)->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '120000.00')
            ->assertJsonPath('data.items.0.available_quantity', '5.000')
            ->assertJsonPath('data.items.0.insufficient_stock', false)
            ->assertJsonPath('data.grand_total', '240000.00');
        $this->postJson('/api/admin/sales-orders/retail-preview', [
            ...$payload, 'warehouse_id' => $otherWarehouse->id,
        ])->assertOk()->assertJsonPath('data.items.0.insufficient_stock', true);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_admin_can_create_confirmed_retail_order_atomically_without_marking_payment_paid(): void
    {
        $this->admin();
        [$warehouse, $variant] = $this->catalog();
        $this->stock($warehouse, $variant, '3');
        $body = [...$this->body($warehouse, $variant),
            'payment_method' => 'bank_transfer', 'shipping_district' => 'Ward 1',
            'confirm' => true, 'confirm_operation_key' => (string) Str::uuid(),
        ];

        $created = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()
            ->assertJsonPath('data.order_status', 'confirmed')
            ->assertJsonPath('data.fulfillment_status', 'reserved')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payment_method', 'bank_transfer')
            ->assertJsonPath('data.shipping_district', 'Ward 1');
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->postJson('/api/admin/sales-orders', $body)->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);

        $insufficient = [...$this->body($warehouse, $variant, '2'),
            'payment_method' => 'cod', 'confirm' => true,
            'confirm_operation_key' => (string) Str::uuid(),
        ];
        $this->postJson('/api/admin/sales-orders', $insufficient)->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_admin_can_search_create_and_reuse_retail_customer_address(): void
    {
        $this->admin();
        $this->postJson('/api/admin/sales-orders/buyers', [
            'name' => 'Retail Buyer', 'email' => 'retail-buyer@example.com', 'phone' => '0901234567',
        ])->assertCreated()->assertJsonPath('data.name', 'Retail Buyer');
        $buyer = User::query()->where('email', 'retail-buyer@example.com')->firstOrFail();
        $this->assertTrue($buyer->isCustomer());
        $this->getJson('/api/admin/sales-orders/buyers?search=0901234567')->assertOk()
            ->assertJsonPath('data.0.id', $buyer->id);
        [$warehouse, $variant] = $this->catalog();
        $body = [...$this->body($warehouse, $variant), 'buyer_user_id' => $buyer->id,
            'shipping_address_line1' => '12 Nguyen Hue', 'shipping_district' => 'Ben Nghe',
        ];
        $this->postJson('/api/admin/sales-orders', $body)->assertCreated();
        $this->getJson("/api/admin/sales-orders/buyers/{$buyer->id}")->assertOk()
            ->assertJsonPath('data.last_shipping.shipping_address_line1', '12 Nguyen Hue')
            ->assertJsonPath('data.last_shipping.shipping_district', 'Ben Nghe');
        $this->postJson('/api/admin/sales-orders/buyers', [
            'name' => 'Duplicate', 'email' => $buyer->email, 'phone' => '0909999999',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_admin_can_edit_retail_draft_then_confirm_with_current_price_and_stock(): void
    {
        $this->admin();
        [$warehouse, $variant, $price] = $this->catalog();
        $this->stock($warehouse, $variant, '4');
        $body = [...$this->body($warehouse, $variant, '1'), 'payment_method' => 'cod'];
        $id = $this->postJson('/api/admin/sales-orders', $body)->assertCreated()->json('data.id');
        $price->update(['unit_price' => '130000.00']);

        $edit = [...$body, 'operation_key' => (string) Str::uuid(),
            'recipient_name' => 'Updated Recipient',
            'items' => [['sku' => $variant->sku, 'quantity' => '3']],
        ];
        $this->postJson("/api/admin/sales-orders/{$id}/draft", $edit)->assertOk()
            ->assertJsonPath('data.order_status', 'draft')
            ->assertJsonPath('data.recipient_name', 'Updated Recipient')
            ->assertJsonPath('data.items.0.unit_price_snapshot', '130000.00')
            ->assertJsonPath('data.grand_total', '390000.00');
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->postJson("/api/admin/sales-orders/{$id}/draft", $edit)->assertOk();
        $this->assertDatabaseCount('sales_order_items', 1);

        $tooMany = [...$edit, 'operation_key' => (string) Str::uuid(),
            'confirm' => true, 'confirm_operation_key' => (string) Str::uuid(),
            'items' => [['sku' => $variant->sku, 'quantity' => '5']],
        ];
        $this->postJson("/api/admin/sales-orders/{$id}/draft", $tooMany)->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->getJson("/api/admin/sales-orders/{$id}")->assertOk()
            ->assertJsonPath('data.order_status', 'draft')
            ->assertJsonPath('data.items.0.quantity', '3.000');
        $this->assertDatabaseCount('inventory_reservations', 0);

        $confirm = [...$edit, 'operation_key' => (string) Str::uuid(),
            'confirm' => true, 'confirm_operation_key' => (string) Str::uuid(),
        ];
        $this->postJson("/api/admin/sales-orders/{$id}/draft", $confirm)->assertOk()
            ->assertJsonPath('data.order_status', 'confirmed')
            ->assertJsonPath('data.fulfillment_status', 'reserved')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->postJson("/api/admin/sales-orders/{$id}/draft", $confirm)->assertOk()
            ->assertJsonPath('data.order_status', 'confirmed');
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->postJson("/api/admin/sales-orders/{$id}/draft", [
            ...$edit, 'operation_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
    }
}
