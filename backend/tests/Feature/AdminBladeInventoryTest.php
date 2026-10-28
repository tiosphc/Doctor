<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AdminInventoryReadService;
use App\Services\InventoryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeInventoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_inventory_pages_and_writes_require_admin(): void
    {
        $variant = $this->variant();
        $warehouse = Warehouse::factory()->create();

        foreach (['index', 'movements', 'reconciliation'] as $name) {
            $this->get(route('admin.web.inventory.'.$name))->assertRedirect('/login');
        }
        $this->get(route('admin.web.inventory.show', $variant))->assertRedirect('/login');
        $this->post(route('admin.web.inventory.opening.store', [$variant, $warehouse]), $this->operation($variant, $warehouse, '1'))->assertRedirect('/login');

        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('admin.web.inventory.index'))->assertForbidden();
        $this->get(route('admin.web.inventory.show', $variant))->assertForbidden();
        $this->get(route('admin.web.inventory.operation', [$variant, $warehouse, 'opening']))->assertForbidden();
        $this->post(route('admin.web.inventory.opening.store', [$variant, $warehouse]), $this->operation($variant, $warehouse, '1'))->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_grouped_overview_filters_by_sku_warehouse_and_low_stock(): void
    {
        $this->admin();
        $first = Warehouse::factory()->create();
        $second = Warehouse::factory()->create();
        $low = $this->variant('LOW-SKU');
        $low->product->update(['default_low_stock_threshold' => '3']);
        $high = ProductVariant::factory()->for($low->product)->create(['sku' => 'HIGH-SKU', 'track_inventory' => true]);
        $other = $this->variant('OTHER-SKU');
        $this->post(route('admin.web.inventory.opening.store', [$low, $first]), $this->operation($low, $first, '2'))->assertRedirect();
        $this->post(route('admin.web.inventory.opening.store', [$low, $second]), $this->operation($low, $second, '8'))->assertRedirect();
        $this->post(route('admin.web.inventory.opening.store', [$high, $first]), $this->operation($high, $first, '9'))->assertRedirect();
        $this->post(route('admin.web.inventory.opening.store', [$other, $first]), $this->operation($other, $first, '9'))->assertRedirect();

        $this->get(route('admin.web.inventory.index', ['search' => 'LOW-SKU', 'warehouse_id' => $first->id, 'low_stock' => 1]))
            ->assertOk()->assertViewHas('products', fn ($page): bool => $page->total() === 1)
            ->assertSee('LOW-SKU')->assertDontSee('OTHER-SKU')
            ->assertViewHas('balances', fn ($balances): bool => $balances->get($low->id)->count() === 1)
            ->assertSee($first->code);
        $this->get(route('admin.web.inventory.index', ['warehouse_id' => $second->id, 'low_stock' => 1]))
            ->assertOk()->assertViewHas('products', fn ($page): bool => $page->total() === 0);
        $low->update(['status' => 'inactive']);
        $this->get(route('admin.web.inventory.index', ['warehouse_id' => $first->id, 'low_stock' => 1, 'status' => 'active']))
            ->assertOk()->assertViewHas('products', fn ($page): bool => $page->total() === 0);
        $this->get(route('admin.web.inventory.index', ['warehouse_id' => $first->id, 'low_stock' => 1, 'status' => 'inactive']))
            ->assertOk()->assertViewHas('products', fn ($page): bool => $page->total() === 1);
    }

    public function test_opening_is_once_per_sku_and_warehouse_and_replay_is_idempotent(): void
    {
        $this->admin();
        $variant = $this->variant();
        $first = Warehouse::factory()->create();
        $second = Warehouse::factory()->create();
        $opening = $this->operation($variant, $first, '0');

        $this->post(route('admin.web.inventory.opening.store', [$variant, $first]), $opening)->assertRedirect(route('admin.web.inventory.show', $variant));
        $this->post(route('admin.web.inventory.opening.store', [$variant, $first]), $opening)->assertRedirect();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->get(route('admin.web.inventory.operation', [$variant, $first, 'opening']))->assertOk()->assertDontSee('name="quantity"', false);
        $this->post(route('admin.web.inventory.opening.store', [$variant, $first]), $this->operation($variant, $first, '1'))
            ->assertRedirect()->assertSessionHasErrors('inventory');
        $this->post(route('admin.web.inventory.opening.store', [$variant, $second]), $this->operation($variant, $second, '4'))->assertRedirect();
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $first->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => '0.000']);
    }

    public function test_adjustment_and_manual_receipt_share_ledger_and_reservation_guard(): void
    {
        $admin = $this->admin();
        $variant = $this->variant();
        $warehouse = Warehouse::factory()->create();
        $this->post(route('admin.web.inventory.opening.store', [$variant, $warehouse]), $this->operation($variant, $warehouse, '10'))->assertRedirect();
        DB::table('inventory_balances')->where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->update(['reserved_quantity' => '8.000']);

        $this->get(route('admin.web.inventory.operation', [$variant, $warehouse, 'adjustment']))->assertOk()->assertSee('name="quantity"', false);
        $this->get(route('admin.web.inventory.operation', [$variant, $warehouse, 'receipt']))->assertOk()->assertSee('name="quantity"', false);
        $this->post(route('admin.web.inventory.adjustment.store', [$variant, $warehouse]), [...$this->operation($variant, $warehouse, '-3'), 'reason_code' => 'DAMAGED'])
            ->assertRedirect()->assertSessionHasErrors('inventory');
        $this->assertDatabaseCount('stock_movements', 1);
        $this->post(route('admin.web.inventory.receipt.store', [$variant, $warehouse]), [...$this->operation($variant, $warehouse, '4'), 'reference_type' => 'MANUAL', 'reference_id' => 'INV-001'])
            ->assertRedirect(route('admin.web.inventory.show', $variant));
        $this->post(route('admin.web.inventory.adjustment.store', [$variant, $warehouse]), [...$this->operation($variant, $warehouse, '-3'), 'reason_code' => 'DAMAGED'])
            ->assertRedirect(route('admin.web.inventory.show', $variant));

        $this->assertDatabaseHas('inventory_balances', ['product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'on_hand_quantity' => '11.000', 'reserved_quantity' => '8.000']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'GOODS_RECEIPT', 'reference_id' => 'INV-001', 'actor_user_id' => $admin->id]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'ADJUSTMENT_OUT', 'quantity' => '-3.000', 'reason_code' => 'DAMAGED']);
        $this->get(route('admin.web.inventory.movements', ['movement_type' => 'ADJUSTMENT_OUT']))->assertOk()->assertSee('DAMAGED')->assertDontSee('INV-001');
        $this->get(route('admin.web.inventory.reconciliation'))->assertOk()->assertViewHas('report', fn (array $report): bool => $report['mismatched'] === 0);
    }

    public function test_invalid_identity_and_quantity_never_write_inventory(): void
    {
        $this->admin();
        $variant = $this->variant();
        $other = $this->variant();
        $warehouse = Warehouse::factory()->create();

        $this->post(route('admin.web.inventory.opening.store', [$variant, $warehouse]), [...$this->operation($variant, $warehouse, '3'), 'product_variant_id' => $other->id])
            ->assertRedirect()->assertSessionHasErrors('inventory');
        $this->post(route('admin.web.inventory.opening.store', [$variant, $warehouse]), $this->operation($variant, $warehouse, '-1'))
            ->assertRedirect()->assertSessionHasErrors('quantity');
        $this->post(route('admin.web.inventory.opening.store', [$variant, $warehouse]), [...$this->operation($variant, $warehouse, '3'), 'reason_detail' => ''])
            ->assertRedirect()->assertSessionHasErrors('reason_detail');
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjustments_change_only_the_selected_warehouse_and_require_a_reason(): void
    {
        $this->admin();
        $variant = $this->variant();
        $first = Warehouse::factory()->create();
        $second = Warehouse::factory()->create();
        $this->post(route('admin.web.inventory.opening.store', [$variant, $first]), $this->operation($variant, $first, '5'))->assertRedirect();
        $this->post(route('admin.web.inventory.opening.store', [$variant, $second]), $this->operation($variant, $second, '9'))->assertRedirect();

        $this->post(route('admin.web.inventory.adjustment.store', [$variant, $first]), [...$this->operation($variant, $first, '2'), 'reason_code' => 'COUNT_CORRECTION'])
            ->assertRedirect(route('admin.web.inventory.show', $variant));
        $this->post(route('admin.web.inventory.adjustment.store', [$variant, $first]), [...$this->operation($variant, $first, '-1'), 'reason_code' => 'DAMAGED'])
            ->assertRedirect(route('admin.web.inventory.show', $variant));
        $this->post(route('admin.web.inventory.adjustment.store', [$variant, $first]), $this->operation($variant, $first, '1'))
            ->assertRedirect()->assertSessionHasErrors('reason_code');
        $this->post(route('admin.web.inventory.adjustment.store', [$variant, $first]), [...$this->operation($variant, $first, '1'), 'warehouse_id' => 999999, 'reason_code' => 'FOUND'])
            ->assertRedirect()->assertSessionHasErrors('warehouse_id');

        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $first->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => '6.000']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $second->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => '9.000']);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->get(route('admin.web.inventory.show', [$variant, 'warehouse_id' => $first->id, 'movement_type' => 'ADJUSTMENT_IN']))
            ->assertOk()->assertViewHas('movements', fn ($page): bool => $page->total() === 1);
    }

    public function test_grouped_overview_reads_many_skus_with_bounded_queries(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['track_inventory' => true, 'default_low_stock_threshold' => '3']);
        $unit = Unit::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variants = ProductVariant::factory()->count(6)->for($product)->create(['unit_id' => $unit->id, 'track_inventory' => true]);
        foreach ($variants as $variant) {
            app(InventoryService::class)->opening($this->operation($variant, $warehouse, '2'), $admin->id);
        }

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queryCount++;
            }
        });
        $overview = app(AdminInventoryReadService::class)->overview([]);

        $this->assertSame(1, $overview['products']->total());
        $this->assertSame(6, $overview['low_stock_counts'][$product->id]);
        $this->assertLessThanOrEqual(9, $queryCount);
    }

    private function admin(): User
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function variant(?string $sku = null): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['track_inventory' => true])->id,
            'track_inventory' => true,
            ...($sku !== null ? ['sku' => $sku] : []),
        ]);
    }

    /** @return array<string, mixed> */
    private function operation(ProductVariant $variant, Warehouse $warehouse, string $quantity): array
    {
        return [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'operation_key' => (string) Str::uuid(),
            'reason_detail' => 'Checked physical stock',
        ];
    }
}
