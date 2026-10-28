<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBladeProcurementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    /** @return array{supplier_id: int, warehouse_id: int, items: list<array<string, mixed>>} */
    private function orderPayload(?Warehouse $warehouse = null, ?ProductVariant $variant = null): array
    {
        $supplier = Supplier::create(['code' => 'SUP-01', 'name' => 'Supplier One', 'status' => 'active']);
        $warehouse ??= Warehouse::factory()->create();
        $variant ??= ProductVariant::factory()->create(['track_inventory' => true]);

        return ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '10', 'unit_price' => '150000']]];
    }

    public function test_supplier_and_purchase_order_web_routes_require_admin(): void
    {
        $this->withoutVite();
        foreach (['/admin/suppliers', '/admin/suppliers/create', '/admin/purchase-orders', '/admin/purchase-orders/create'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->customer()->create());
        $this->get('/admin/suppliers')->assertForbidden();
        $this->get('/admin/purchase-orders')->assertForbidden();
        $this->post('/admin/suppliers', ['code' => 'SUP-01', 'name' => 'Supplier One'])->assertForbidden();
    }

    public function test_supplier_blade_crud_filters_status_and_preserves_order_history(): void
    {
        $this->admin();
        $this->post('/admin/suppliers', ['code' => ' sup-01 ', 'name' => 'Supplier One', 'status' => 'active'])->assertRedirect();
        $supplier = Supplier::query()->where('code', 'SUP-01')->firstOrFail();
        $this->get('/admin/suppliers?search=SUP-01&status=active')->assertOk()->assertSee('Supplier One');
        $this->from('/admin/suppliers/create')->post('/admin/suppliers', ['code' => 'SUP-01', 'name' => 'Duplicate'])->assertSessionHasErrors('code');
        $this->patch('/admin/suppliers/'.$supplier->id, ['name' => 'Supplier Revised', 'status' => 'inactive'])->assertRedirect();
        $this->get('/admin/suppliers?status=inactive')->assertOk()->assertSee('Supplier Revised');
        $this->get('/admin/suppliers?status=active')->assertOk()->assertDontSee('Supplier Revised');
        $this->patch('/admin/suppliers/'.$supplier->id, ['status' => 'active'])->assertRedirect();

        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $this->post('/admin/purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '100']]])->assertRedirect();
        $this->from(route('admin.web.suppliers.edit', $supplier))->patch('/admin/suppliers/'.$supplier->id, ['code' => 'CHANGED'])
            ->assertSessionHasErrors('code');
        $this->patch('/admin/suppliers/'.$supplier->id, ['status' => 'inactive'])->assertRedirect();
        $this->get('/admin/purchase-orders/'.PurchaseOrder::query()->firstOrFail()->id)->assertOk()->assertSee('Supplier Revised');
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'code' => 'SUP-01', 'status' => 'inactive']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/suppliers?search=SUP-01')->assertOk()->assertJsonPath('data.0.name', 'Supplier Revised');
    }

    public function test_purchase_order_form_uses_variant_unit_and_server_calculates_total(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $secondVariant = ProductVariant::factory()->create(['track_inventory' => true]);
        $payload['items'][] = ['product_variant_id' => $secondVariant->id, 'quantity' => '3', 'unit_price' => '200'];
        $this->get('/admin/purchase-orders/create')->assertOk()
            ->assertSee(ProductVariant::query()->findOrFail($payload['items'][0]['product_variant_id'])->sku)
            ->assertSee($secondVariant->unit->symbol);
        $this->post('/admin/purchase-orders', $payload)->assertRedirect();
        $order = PurchaseOrder::query()->firstOrFail();
        $this->assertSame('1500600.00', $order->total_amount);
        $this->assertSame(2, $order->items()->count());
        $this->get('/admin/purchase-orders?warehouse_id='.$order->warehouse_id)->assertOk()->assertSee($order->code);
        $this->get('/admin/purchase-orders/'.$order->id)->assertOk()->assertSee($secondVariant->sku);
        $this->assertDatabaseCount('inventory_balances', 0);

        $invalid = $payload;
        $invalid['items'][1]['product_variant_id'] = $payload['items'][0]['product_variant_id'];
        $this->from('/admin/purchase-orders/create')->post('/admin/purchase-orders', $invalid)->assertSessionHasErrors('items.0.product_variant_id');
        $invalid['items'][1]['product_variant_id'] = 999999;
        $this->from('/admin/purchase-orders/create')->post('/admin/purchase-orders', $invalid)->assertSessionHasErrors('items.1.product_variant_id');
        $this->patch('/admin/purchase-orders/'.$order->id, ['note' => 'Revised'])->assertRedirect();
        $this->post('/admin/purchase-orders/'.$order->id.'/issue')->assertRedirect();
        $this->from('/admin/purchase-orders/'.$order->id.'/edit')->patch('/admin/purchase-orders/'.$order->id, ['note' => 'Too late'])->assertSessionHasErrors('operation');
        $this->get('/admin/purchase-orders/'.$order->id.'/edit')->assertNotFound();
    }

    public function test_blade_receipt_is_partial_idempotent_and_scoped_to_po_warehouse(): void
    {
        $this->admin();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $payload = $this->orderPayload($warehouseA);
        $this->post('/admin/purchase-orders', $payload)->assertRedirect();
        $order = PurchaseOrder::query()->with('items')->firstOrFail();
        $this->post('/admin/purchase-orders/'.$order->id.'/issue')->assertRedirect();
        $receipt = ['operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $order->items[0]->id, 'quantity' => '4']]];
        $url = '/admin/purchase-orders/'.$order->id.'/goods-receipts';
        $this->post($url, $receipt)->assertRedirect();
        $this->post($url, $receipt)->assertRedirect();
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseHas('stock_movements', ['warehouse_id' => $warehouseA->id, 'movement_type' => 'GOODS_RECEIPT', 'quantity' => '4.000']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouseA->id,
            'product_variant_id' => $payload['items'][0]['product_variant_id'], 'on_hand_quantity' => '4.000', 'average_unit_cost' => '150000.000000']);
        $this->assertDatabaseMissing('inventory_balances', ['warehouse_id' => $warehouseB->id, 'product_variant_id' => $payload['items'][0]['product_variant_id']]);
        $this->assertSame('partially_received', $order->refresh()->status);
        $this->from(route('admin.web.purchase-orders.show', $order))->post($url, [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $order->items[0]->id, 'quantity' => '7']],
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('goods_receipts', 1);
        $warehouseA->update(['status' => 'inactive']);
        $this->from(route('admin.web.purchase-orders.show', $order))->post($url, [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $order->items[0]->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_order_rejects_inactive_parties_and_invalid_purchase_lines(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $supplier = Supplier::query()->findOrFail($payload['supplier_id']);
        $supplier->update(['status' => 'inactive']);
        $this->from('/admin/purchase-orders/create')->post('/admin/purchase-orders', $payload)->assertSessionHasErrors('operation');
        $supplier->update(['status' => 'active']);
        Warehouse::query()->findOrFail($payload['warehouse_id'])->update(['status' => 'inactive']);
        $this->from('/admin/purchase-orders/create')->post('/admin/purchase-orders', $payload)->assertSessionHasErrors('operation');
        Warehouse::query()->findOrFail($payload['warehouse_id'])->update(['status' => 'active']);
        $payload['items'][0]['quantity'] = '1.5';
        $payload['items'][0]['unit_price'] = '-1';
        $this->from('/admin/purchase-orders/create')->post('/admin/purchase-orders', $payload)
            ->assertSessionHasErrors(['items.0.quantity', 'items.0.unit_price']);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_blade_receipt_rolls_back_all_lines_when_one_item_belongs_to_another_order(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $secondVariant = ProductVariant::factory()->create(['track_inventory' => true]);
        $payload['items'][] = ['product_variant_id' => $secondVariant->id, 'quantity' => '2', 'unit_price' => '100'];
        $this->post('/admin/purchase-orders', $payload)->assertRedirect();
        $first = PurchaseOrder::query()->with('items')->firstOrFail();
        $this->post('/admin/purchase-orders', $payload)->assertRedirect();
        $second = PurchaseOrder::query()->with('items')->latest('id')->firstOrFail();
        $this->post('/admin/purchase-orders/'.$first->id.'/issue')->assertRedirect();
        $this->post('/admin/purchase-orders/'.$second->id.'/issue')->assertRedirect();
        $this->from(route('admin.web.purchase-orders.show', $first))->post('/admin/purchase-orders/'.$first->id.'/goods-receipts', [
            'operation_key' => (string) Str::uuid(), 'items' => [
                ['purchase_order_item_id' => $first->items[0]->id, 'quantity' => '2'],
                ['purchase_order_item_id' => $second->items[1]->id, 'quantity' => '1'],
            ],
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $first->items[0]->id, 'received_quantity' => '0.000']);
    }

    public function test_blade_purchase_return_limits_quantity_and_rolls_back_on_inventory_failure(): void
    {
        $this->admin();
        $payload = $this->orderPayload();
        $this->post('/admin/purchase-orders', $payload)->assertRedirect();
        $order = PurchaseOrder::query()->with('items')->firstOrFail();
        $this->post('/admin/purchase-orders/'.$order->id.'/issue')->assertRedirect();
        $this->post('/admin/purchase-orders/'.$order->id.'/goods-receipts', [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $order->items[0]->id, 'quantity' => '4']],
        ])->assertRedirect();
        $receiptItemId = DB::table('goods_receipt_items')->value('id');
        $url = '/admin/purchase-orders/'.$order->id.'/returns';
        $return = ['operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['goods_receipt_item_id' => $receiptItemId, 'quantity' => '2']]];
        $this->post($url, $return)->assertRedirect();
        $this->post($url, $return)->assertRedirect();
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'PURCHASE_RETURN', 'quantity' => '-2.000']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $order->warehouse_id, 'on_hand_quantity' => '2.000']);
        $this->from(route('admin.web.purchase-orders.show', $order))->post($url, [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Too many',
            'items' => [['goods_receipt_item_id' => $receiptItemId, 'quantity' => '3']],
        ])->assertSessionHasErrors('operation');
        DB::table('inventory_balances')->where('warehouse_id', $order->warehouse_id)->update(['reserved_quantity' => '2.000']);
        $this->from(route('admin.web.purchase-orders.show', $order))->post($url, [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Reserved',
            'items' => [['goods_receipt_item_id' => $receiptItemId, 'quantity' => '1']],
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_blade_purchase_payment_and_cancel_follow_existing_transitions(): void
    {
        $this->admin();
        $this->post('/admin/purchase-orders', $this->orderPayload())->assertRedirect();
        $order = PurchaseOrder::query()->firstOrFail();
        $this->post('/admin/purchase-orders/'.$order->id.'/issue')->assertRedirect();
        $payment = ['operation_key' => (string) Str::uuid(), 'amount' => '500000', 'payment_method' => 'bank_transfer', 'paid_at' => now()->toDateTimeString()];
        $this->post('/admin/purchase-orders/'.$order->id.'/payments', $payment)->assertRedirect();
        $this->post('/admin/purchase-orders/'.$order->id.'/payments', $payment)->assertRedirect();
        $this->assertSame('partially_paid', $order->refresh()->payment_status);
        $this->assertDatabaseCount('purchase_order_payments', 1);
        $this->from(route('admin.web.purchase-orders.show', $order))->post('/admin/purchase-orders/'.$order->id.'/cancel')->assertSessionHasErrors('operation');
        $this->assertSame('ordered', $order->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
