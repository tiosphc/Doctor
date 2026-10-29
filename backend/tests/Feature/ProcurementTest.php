<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** @return array{supplier_id: int, warehouse_id: int, items: list<array<string, mixed>>} */
    private function orderPayload(?ProductVariant $variant = null): array
    {
        $supplierId = $this->postJson('/api/admin/suppliers', ['code' => 'SUP-01', 'name' => 'Supplier One'])->assertCreated()->json('data.id');
        $warehouse = Warehouse::factory()->create();
        $variant ??= ProductVariant::factory()->create(['track_inventory' => true]);

        return ['supplier_id' => $supplierId, 'warehouse_id' => $warehouse->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '10', 'unit_price' => '150000']]];
    }

    public function test_admin_only_supplier_master_and_order_draft_do_not_change_stock(): void
    {
        $this->getJson('/api/admin/suppliers')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/purchase-orders')->assertForbidden();
        $this->admin();
        $payload = $this->orderPayload();
        $this->postJson('/api/admin/suppliers', ['code' => 'SUP-01', 'name' => 'Duplicate'])->assertUnprocessable();
        $order = $this->postJson('/api/admin/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', '1500000.00');
        $id = $order->json('data.id');
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->patchJson("/api/admin/purchase-orders/{$id}", ['note' => 'Revised'])->assertOk()->assertJsonPath('data.note', 'Revised');
        $this->postJson("/api/admin/purchase-orders/{$id}/issue")->assertOk()->assertJsonPath('data.status', 'ordered');
        $this->patchJson("/api/admin/purchase-orders/{$id}", ['note' => 'Too late'])->assertConflict();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_purchase_order_and_receipt_reject_fractional_quantities_without_stock_writes(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $invalid = $payload;
        $invalid['items'][0]['quantity'] = '1.001';
        $this->postJson('/api/admin/purchase-orders', $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('purchase_orders', 0);

        $order = $this->postJson('/api/admin/purchase-orders', $payload)->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertOk();
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '0.5']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_purchase_order_list_filters_by_receiving_warehouse(): void
    {
        $this->admin();
        $firstPayload = $this->orderPayload();
        $secondWarehouse = Warehouse::factory()->create();
        $this->postJson('/api/admin/purchase-orders', $firstPayload)->assertCreated();
        $this->postJson('/api/admin/purchase-orders', [
            ...$firstPayload, 'warehouse_id' => $secondWarehouse->id,
        ])->assertCreated();

        $this->getJson('/api/admin/purchase-orders?warehouse_id='.$secondWarehouse->id)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.warehouse_id', $secondWarehouse->id);
    }

    public function test_partial_receipts_and_supplier_return_are_atomic_and_idempotent(): void
    {
        $this->admin();
        $order = $this->postJson('/api/admin/purchase-orders', $this->orderPayload())->assertCreated()->json('data');
        $id = $order['id'];
        $itemId = $order['items'][0]['id'];
        $this->postJson("/api/admin/purchase-orders/{$id}/issue")->assertOk();

        $receipt = ['operation_key' => (string) Str::uuid(), 'supplier_reference' => 'DEL-01',
            'items' => [['purchase_order_item_id' => $itemId, 'quantity' => '4']]];
        $first = $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", $receipt)->assertCreated()
            ->assertJsonPath('data.items.0.quantity', '4.000');
        $firstReceiptId = $first->json('data.id');
        $receiptItemId = $first->json('data.items.0.id');
        $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", $receipt)->assertCreated()
            ->assertJsonPath('data.id', $firstReceiptId);
        $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", [...$receipt, 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => '5']]])
            ->assertConflict()->assertJsonPath('code', 'PROCUREMENT_OPERATION_KEY_CONFLICT');
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->getJson("/api/admin/purchase-orders/{$id}")->assertJsonPath('data.status', 'partially_received');
        $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => '7']],
        ])->assertConflict()->assertJsonPath('code', 'PURCHASE_ORDER_OVER_RECEIPT');
        $this->assertDatabaseCount('goods_receipts', 1);

        $return = ['operation_key' => (string) Str::uuid(), 'reason' => 'Damaged carton',
            'items' => [['goods_receipt_item_id' => $receiptItemId, 'quantity' => '2']]];
        $returned = $this->postJson("/api/admin/purchase-orders/{$id}/returns", $return)->assertCreated();
        $this->postJson("/api/admin/purchase-orders/{$id}/returns", $return)->assertCreated()
            ->assertJsonPath('data.id', $returned->json('data.id'));
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $order['warehouse_id'],
            'product_variant_id' => $order['items'][0]['product_variant_id'], 'on_hand_quantity' => '2.000']);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'PURCHASE_RETURN', 'quantity' => '-2.000']);
        $this->getJson('/api/admin/stock-movements?movement_type=PURCHASE_RETURN')
            ->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/admin/purchase-orders/{$id}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Extra',
            'items' => [['goods_receipt_item_id' => $receiptItemId, 'quantity' => '3']],
        ])->assertConflict()->assertJsonPath('code', 'PURCHASE_RETURN_EXCEEDS_RECEIPT');
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertSame(0, DB::table('inventory_balances')->whereRaw('on_hand_quantity <> (SELECT SUM(quantity) FROM stock_movements WHERE stock_movements.warehouse_id = inventory_balances.warehouse_id AND stock_movements.product_variant_id = inventory_balances.product_variant_id)')->count());
    }

    public function test_completed_order_and_stock_reservation_prevent_invalid_transitions(): void
    {
        $this->admin();
        $order = $this->postJson('/api/admin/purchase-orders', $this->orderPayload())->assertCreated()->json('data');
        $id = $order['id'];
        $itemId = $order['items'][0]['id'];
        $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => '10']],
        ])->assertConflict();
        $this->postJson("/api/admin/purchase-orders/{$id}/issue")->assertOk();
        $receipt = $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => '10']],
        ])->assertCreated();
        $this->getJson("/api/admin/purchase-orders/{$id}")->assertJsonPath('data.status', 'received');
        $this->postJson("/api/admin/purchase-orders/{$id}/cancel")->assertConflict();
        $this->postJson("/api/admin/purchase-orders/{$id}/goods-receipts", [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => '1']],
        ])->assertConflict();
        DB::table('inventory_balances')->where('warehouse_id', $order['warehouse_id'])->update(['reserved_quantity' => '9.000']);
        $this->postJson("/api/admin/purchase-orders/{$id}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Supplier recall',
            'items' => [['goods_receipt_item_id' => $receipt->json('data.items.0.id'), 'quantity' => '2']],
        ])->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_STOCK');
        $this->assertDatabaseCount('purchase_returns', 0);
    }

    public function test_receipt_rejects_foreign_order_item_and_rolls_back_all_lines(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $otherVariant = ProductVariant::factory()->create(['track_inventory' => true]);
        $payload['items'][] = ['product_variant_id' => $otherVariant->id, 'quantity' => '2', 'unit_price' => '500'];
        $order = $this->postJson('/api/admin/purchase-orders', $payload)->assertCreated()->json('data');
        $other = $this->postJson('/api/admin/purchase-orders', $payload)->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertOk();
        $this->postJson("/api/admin/purchase-orders/{$other['id']}/issue")->assertOk();
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(), 'items' => [
                ['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '3'],
                ['purchase_order_item_id' => $other['items'][1]['id'], 'quantity' => '1'],
            ],
        ])->assertConflict()->assertJsonPath('code', 'PURCHASE_ORDER_ITEM_INVALID');
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $order['items'][0]['id'], 'received_quantity' => '0.000']);
    }

    public function test_inactive_supplier_cannot_be_issued_and_unreceived_order_can_be_cancelled(): void
    {
        $this->admin();
        $order = $this->postJson('/api/admin/purchase-orders', $this->orderPayload())->assertCreated()->json('data');
        $this->patchJson('/api/admin/suppliers/'.$order['supplier_id'], ['status' => 'inactive'])->assertOk();
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertConflict()->assertJsonPath('code', 'SUPPLIER_INACTIVE');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertConflict();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_supplier_payments_record_external_settlement_without_changing_stock(): void
    {
        $this->admin();
        $order = $this->postJson('/api/admin/purchase-orders', $this->orderPayload())->assertCreated()->json('data');
        $url = "/api/admin/purchase-orders/{$order['id']}/payments";
        $payment = ['operation_key' => (string) Str::uuid(), 'amount' => '500000',
            'payment_method' => 'bank_transfer', 'paid_at' => now()->toDateTimeString(),
            'external_reference' => 'TCB-001'];

        $this->postJson($url, $payment)->assertConflict()->assertJsonPath('code', 'PURCHASE_ORDER_NOT_ISSUED');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertOk();
        $this->postJson($url, $payment)->assertOk()->assertJsonPath('data.payment_status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', '500000.00');
        $this->postJson($url, $payment)->assertOk()->assertJsonCount(1, 'data.payments');
        $this->postJson($url, [...$payment, 'amount' => '600000'])
            ->assertConflict()->assertJsonPath('code', 'PROCUREMENT_OPERATION_KEY_CONFLICT');
        $this->postJson($url, [...$payment, 'operation_key' => (string) Str::uuid(), 'amount' => '1000001'])
            ->assertConflict()->assertJsonPath('code', 'PURCHASE_PAYMENT_EXCEEDS_TOTAL');
        $this->postJson($url, [...$payment, 'operation_key' => (string) Str::uuid(), 'amount' => '1000000'])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.paid_amount', '1500000.00');
        $this->assertDatabaseCount('purchase_order_payments', 2);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
    }

    public function test_purchase_receipts_establish_weighted_average_cost_by_warehouse(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $first = $this->postJson('/api/admin/purchase-orders', $payload)->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$first['id']}/issue")->assertOk();
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->postJson("/api/admin/purchase-orders/{$first['id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['purchase_order_item_id' => $first['items'][0]['id'], 'quantity' => '4']],
        ])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $payload['warehouse_id'],
            'product_variant_id' => $payload['items'][0]['product_variant_id'],
            'on_hand_quantity' => '4.000', 'average_unit_cost' => '150000.000000']);

        $secondPayload = [...$payload, 'items' => [[...$payload['items'][0], 'unit_price' => '200000']]];
        $second = $this->postJson('/api/admin/purchase-orders', $secondPayload)->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$second['id']}/issue")->assertOk();
        $this->postJson("/api/admin/purchase-orders/{$second['id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['purchase_order_item_id' => $second['items'][0]['id'], 'quantity' => '6']],
        ])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $payload['warehouse_id'],
            'product_variant_id' => $payload['items'][0]['product_variant_id'],
            'on_hand_quantity' => '10.000', 'average_unit_cost' => '180000.000000']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'GOODS_RECEIPT',
            'unit_cost' => '200000.000000', 'cost_amount' => '1200000.00']);
    }

    public function test_sales_shipment_snapshots_warehouse_cost_without_changing_selling_price(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $variant = ProductVariant::query()->findOrFail($payload['items'][0]['product_variant_id']);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '300000.00']);
        $purchase = $this->postJson('/api/admin/purchase-orders', $payload)->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$purchase['id']}/issue")->assertOk();
        $this->postJson("/api/admin/purchase-orders/{$purchase['id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['purchase_order_item_id' => $purchase['items'][0]['id'], 'quantity' => '10']],
        ])->assertCreated();
        $order = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => User::factory()->customer()->create()->id,
            'warehouse_id' => $payload['warehouse_id'], 'currency' => 'VND',
            'recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->assertJsonPath('data.grand_total', '600000.00')->json('data');
        $this->postJson("/api/admin/sales-orders/{$order['id']}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$order['id']}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order['items'][0]['id'], 'quantity' => '2']],
        ])->assertOk();
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALES_ORDER_SHIPMENT',
            'reference_id' => (string) $order['items'][0]['id'],
            'unit_cost' => '150000.000000', 'cost_amount' => '300000.00']);
        $this->assertDatabaseHas('sales_order_items', ['id' => $order['items'][0]['id'],
            'unit_price_snapshot' => '300000.00']);
        $this->getJson("/api/admin/sales-orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.cogs_total', '300000.00')
            ->assertJsonPath('data.gross_profit', '300000.00');
    }
}
