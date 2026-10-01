<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_balance_search_matches_variant_name_and_product_code_with_warehouse_and_low_stock(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $variant->update(['variant_name' => 'Travel edition']);
        $variant->product->update(['product_code' => 'TRAVEL-CODE', 'default_low_stock_threshold' => '3']);
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '2'))->assertOk();

        $this->getJson("/api/admin/inventory?warehouse_id={$warehouse->id}&low_stock=1&search=Travel%20edition")
            ->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/inventory?search=TRAVEL-CODE')
            ->assertOk()->assertJsonPath('total', 1);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function trackedVariant(?Unit $unit = null): ProductVariant
    {
        return ProductVariant::factory()->create([
            'unit_id' => ($unit ?? Unit::factory()->create())->id,
            'track_inventory' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function operation(Warehouse $warehouse, ProductVariant $variant, string $quantity): array
    {
        return [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'operation_key' => (string) Str::uuid(),
            'reason_detail' => 'Reviewed physical stock count',
        ];
    }

    public function test_warehouse_master_normalizes_code_and_rejects_duplicate_active_default(): void
    {
        $this->admin();

        $first = $this->postJson('/api/admin/warehouses', [
            'code' => ' wh-main ', 'name' => 'Main', 'is_default_sales' => true,
        ])->assertCreated()->assertJsonPath('data.code', 'WH-MAIN');
        $this->postJson('/api/admin/warehouses', [
            'code' => 'WH-MAIN', 'name' => 'Duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $second = $this->postJson('/api/admin/warehouses', [
            'code' => 'wh-branch', 'name' => 'Branch', 'is_default_sales' => true,
        ])->assertConflict()->assertJsonPath('code', 'WAREHOUSE_UNIQUE_CONFLICT');
        $this->assertDatabaseCount('warehouses', 1);
        $id = $first->json('data.id');
        $this->patchJson("/api/admin/warehouses/{$id}", ['name' => 'Main revised'])
            ->assertOk()->assertJsonPath('data.name', 'Main revised');
        $this->getJson('/api/admin/warehouses?search=MAIN&status=active')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_warehouse_dealer_adjustment_is_not_editable(): void
    {
        $this->admin();
        $warehouse = $this->postJson('/api/admin/warehouses', [
            'code' => 'WH-HANOI', 'name' => 'Kho Hà Nội',
        ])->assertCreated()->assertJsonMissingPath('data.dealer_price_adjustment_percent')->json('data');
        $this->patchJson("/api/admin/warehouses/{$warehouse['id']}", [
            'dealer_price_adjustment_percent' => '5.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('dealer_price_adjustment_percent');
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse['id'],
            'dealer_price_adjustment_percent' => '0.00']);
    }

    public function test_opening_receipt_and_adjustments_create_signed_immutable_movements(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();

        $opening = $this->operation($warehouse, $variant, '10');
        $this->postJson('/api/admin/inventory/opening-stock', $opening)->assertOk()
            ->assertJsonPath('data.movement_type', 'OPENING_BALANCE')
            ->assertJsonPath('data.before_on_hand_quantity', '0.000')
            ->assertJsonPath('data.after_on_hand_quantity', '10.000');
        $this->postJson('/api/admin/inventory/opening-stock', $opening)->assertOk();
        $this->postJson('/api/admin/inventory/opening-stock', $this->operation($warehouse, $variant, '1'))
            ->assertConflict()->assertJsonPath('code', 'OPENING_STOCK_ALREADY_RECORDED');

        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '5'))
            ->assertOk()->assertJsonPath('data.after_on_hand_quantity', '15.000');
        $in = [...$this->operation($warehouse, $variant, '2'), 'reason_code' => 'FOUND'];
        $this->postJson('/api/admin/inventory/adjustments', $in)->assertOk()
            ->assertJsonPath('data.movement_type', 'ADJUSTMENT_IN')
            ->assertJsonPath('data.after_on_hand_quantity', '17.000');
        $out = [...$this->operation($warehouse, $variant, '-3'), 'reason_code' => 'DAMAGED'];
        $this->postJson('/api/admin/inventory/adjustments', $out)->assertOk()
            ->assertJsonPath('data.movement_type', 'ADJUSTMENT_OUT')
            ->assertJsonPath('data.quantity', '-3.000')
            ->assertJsonPath('data.after_on_hand_quantity', '14.000');
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'on_hand_quantity' => '14.000', 'reserved_quantity' => '0.000',
        ]);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->getJson('/api/admin/inventory')->assertOk()
            ->assertJsonPath('data.0.available_quantity', '14.000')
            ->assertJsonPath('data.0.variant.sku', $variant->sku);
        $this->getJson('/api/admin/stock-movements?movement_type=ADJUSTMENT_OUT')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.reason_code', 'DAMAGED');
        $this->assertDatabaseCount('audit_logs', 4);
        $this->assertDatabaseHas('audit_logs', ['module' => 'INVENTORY', 'action' => 'ADJUSTMENT_OUT']);
    }

    public function test_zero_opening_records_history_and_locks_only_its_warehouse_and_sku(): void
    {
        $this->admin();
        $firstWarehouse = Warehouse::factory()->create();
        $secondWarehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $zeroOpening = $this->operation($firstWarehouse, $variant, '0');

        $first = $this->postJson('/api/admin/inventory/opening-stock', $zeroOpening)->assertOk()
            ->assertJsonPath('data.before_on_hand_quantity', '0.000')
            ->assertJsonPath('data.after_on_hand_quantity', '0.000');
        $this->postJson('/api/admin/inventory/opening-stock', $zeroOpening)->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/admin/inventory/opening-stock', $this->operation($firstWarehouse, $variant, '5'))
            ->assertConflict()->assertJsonPath('code', 'OPENING_STOCK_ALREADY_RECORDED');
        $this->postJson('/api/admin/inventory/opening-stock', $this->operation($secondWarehouse, $variant, '5'))
            ->assertOk()->assertJsonPath('data.after_on_hand_quantity', '5.000');
        $this->getJson('/api/admin/stock-movements?warehouse_id='.$firstWarehouse->id.'&product_variant_id='.$variant->id)
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_idempotency_reuses_identical_receipt_and_rejects_changed_payload(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $body = $this->operation($warehouse, $variant, '2');

        $first = $this->postJson('/api/admin/inventory/receipts', $body)->assertOk();
        $this->postJson('/api/admin/inventory/receipts', $body)->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/admin/inventory/receipts', [...$body, 'quantity' => '3'])
            ->assertConflict()->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '2.000']);
    }

    public function test_stock_receipt_and_adjustment_reject_fractional_quantities(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();

        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '1.001'))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity')
            ->assertJsonPath('errors.quantity.0', 'Số lượng phải là số nguyên.');
        $this->postJson('/api/admin/inventory/adjustments', [
            ...$this->operation($warehouse, $variant, '-0.5'), 'reason_code' => 'COUNT_CORRECTION',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/admin/inventory/adjustments', [
            ...$this->operation($warehouse, $variant, '0'), 'reason_code' => 'COUNT_CORRECTION',
        ])->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Số lượng điều chỉnh phải khác 0. Vui lòng nhập lại.');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }

    public function test_adjustment_out_cannot_make_on_hand_negative_or_below_reserved(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $this->postJson('/api/admin/inventory/opening-stock', $this->operation($warehouse, $variant, '5'))->assertOk();

        $this->postJson('/api/admin/inventory/adjustments', [
            ...$this->operation($warehouse, $variant, '-6'), 'reason_code' => 'COUNT_CORRECTION',
        ])->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_STOCK');
        DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)->update(['reserved_quantity' => '3.000']);
        $this->postJson('/api/admin/inventory/adjustments', [
            ...$this->operation($warehouse, $variant, '-3'), 'reason_code' => 'COUNT_CORRECTION',
        ])->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_STOCK');
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '5.000', 'reserved_quantity' => '3.000']);
        $this->getJson('/api/admin/inventory')->assertOk()->assertJsonPath('data.0.available_quantity', '2.000');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_mutations_reject_untracked_inactive_and_overprecision_skus(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $untracked = ProductVariant::factory()->create(['track_inventory' => false]);
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $untracked, '1'))
            ->assertConflict()->assertJsonPath('code', 'SKU_NOT_INVENTORY_CAPABLE');
        $variant = $this->trackedVariant();
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '1.5'))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $variant->update(['status' => 'inactive']);
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '1'))
            ->assertConflict()->assertJsonPath('code', 'SKU_NOT_INVENTORY_CAPABLE');
        $variant->update(['status' => 'active']);
        $variant->product->update(['status' => 'inactive']);
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '1'))
            ->assertConflict()->assertJsonPath('code', 'SKU_NOT_INVENTORY_CAPABLE');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inactive_warehouse_blocks_new_operations_but_preserves_history_and_code(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $opening = $this->operation($warehouse, $variant, '4');
        $this->postJson('/api/admin/inventory/opening-stock', $opening)->assertOk();

        $this->patchJson("/api/admin/warehouses/{$warehouse->id}", ['code' => 'WH-CHANGED'])
            ->assertConflict()->assertJsonPath('code', 'WAREHOUSE_CODE_IMMUTABLE');
        $this->patchJson("/api/admin/products/{$variant->product_id}/variants/{$variant->id}", ['sku' => 'SKU-CHANGED'])
            ->assertConflict()->assertJsonPath('code', 'INVENTORY_VARIANT_IDENTITY_IMMUTABLE');
        $this->patchJson("/api/admin/products/{$variant->product_id}/variants/{$variant->id}", ['track_inventory' => false])
            ->assertConflict()->assertJsonPath('code', 'INVENTORY_VARIANT_IDENTITY_IMMUTABLE');
        $this->patchJson("/api/admin/warehouses/{$warehouse->id}", ['status' => 'inactive'])->assertOk();
        $this->postJson('/api/admin/inventory/opening-stock', $opening)->assertOk();
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '1'))
            ->assertConflict()->assertJsonPath('code', 'WAREHOUSE_INACTIVE');
        $this->getJson('/api/admin/inventory')->assertOk()->assertJsonPath('data.0.on_hand_quantity', '4.000');
        $this->getJson('/api/admin/stock-movements')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_reconciliation_reports_mismatch_without_repair(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $this->postJson('/api/admin/inventory/opening-stock', $this->operation($warehouse, $variant, '5'))->assertOk();
        $this->getJson('/api/admin/inventory/reconciliation')->assertOk()
            ->assertJsonPath('data.matched', 1)->assertJsonPath('data.mismatched', 0);

        DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)->update(['on_hand_quantity' => '7.000']);
        $this->getJson('/api/admin/inventory/reconciliation')->assertOk()
            ->assertJsonPath('data.mismatched', 1)
            ->assertJsonPath('data.rows.0.difference', '2.000');
        $this->artisan('inventory:reconcile', ['--fail-on-difference' => true])->assertExitCode(1);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '7.000']);
    }

    public function test_database_constraints_and_ledger_triggers_protect_history(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $movementId = $this->postJson('/api/admin/inventory/opening-stock', $this->operation($warehouse, $variant, '2'))
            ->assertOk()->json('data.id');

        try {
            DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => '9.000']);
            $this->fail('An existing movement must be immutable.');
        } catch (QueryException) {
            $this->assertDatabaseHas('stock_movements', ['id' => $movementId, 'quantity' => '2.000']);
        }
        try {
            DB::table('stock_movements')->where('id', $movementId)->delete();
            $this->fail('An existing movement must not be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('stock_movements', 1);
        }
        try {
            DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)->update(['reserved_quantity' => '3.000']);
            $this->fail('Reserved quantity cannot exceed on hand.');
        } catch (QueryException) {
            $this->assertDatabaseHas('inventory_balances', ['reserved_quantity' => '0.000']);
        }
        $this->patchJson('/api/admin/inventory/1', ['on_hand_quantity' => 999])->assertNotFound();
    }

    public function test_inventory_admin_endpoints_reject_public_and_other_roles(): void
    {
        $this->getJson('/api/admin/inventory')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/receipts', [])->assertUnauthorized();
        foreach (['customer', 'doctor', 'receptionist'] as $role) {
            Sanctum::actingAs(User::factory()->{$role}()->create());
            $this->getJson('/api/admin/warehouses')->assertForbidden();
            $this->getJson('/api/admin/stock-movements')->assertForbidden();
            $this->postJson('/api/admin/inventory/adjustments', [])->assertForbidden();
        }
    }

    public function test_public_product_catalog_does_not_expose_internal_stock(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100', 'minimum_quantity' => 1]);

        $this->getJson("/api/products/{$product->slug}")->assertOk()
            ->assertJsonMissingPath('data.variants.0.on_hand_quantity')
            ->assertJsonMissingPath('data.variants.0.reserved_quantity')
            ->assertJsonMissingPath('data.variants.0.warehouse');
    }

    public function test_inventory_list_low_stock_and_movement_date_filters_use_current_values(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $unit = Unit::factory()->create(['decimal_precision' => 0]);
        $variant = $this->trackedVariant($unit);
        $variant->product->update(['default_low_stock_threshold' => '3']);
        $this->postJson('/api/admin/inventory/receipts', [
            ...$this->operation($warehouse, $variant, '2'),
            'reference_type' => 'MANUAL', 'reference_id' => 'COUNT-2026',
        ])
            ->assertOk()->assertJsonPath('data.after_on_hand_quantity', '2.000');

        $this->getJson("/api/admin/inventory?warehouse_id={$warehouse->id}&low_stock=1")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.low_stock', true);
        $today = now()->toDateString();
        $this->getJson("/api/admin/stock-movements?from={$today}&to={$today}&warehouse_id={$warehouse->id}")
            ->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/stock-movements?reference=COUNT-2026')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_reconciliation_reports_missing_balance_without_recreating_it(): void
    {
        $this->admin();
        $warehouse = Warehouse::factory()->create();
        $variant = $this->trackedVariant();
        $this->postJson('/api/admin/inventory/receipts', $this->operation($warehouse, $variant, '2'))->assertOk();
        DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)->delete();

        $this->getJson('/api/admin/inventory/reconciliation')->assertOk()
            ->assertJsonPath('data.missing_balances', 1)
            ->assertJsonPath('data.orphan_movements', 1)
            ->assertJsonPath('data.rows.0.status', 'missing_balance');
        $this->assertDatabaseCount('inventory_balances', 0);
    }
}
