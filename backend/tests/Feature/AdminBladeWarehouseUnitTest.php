<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBladeWarehouseUnitTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_warehouse_and_unit_routes_require_admin_web_session(): void
    {
        $this->withoutVite();
        foreach (['/admin/warehouses', '/admin/warehouses/create', '/admin/units', '/admin/units/create'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->customer()->create());
        foreach (['/admin/warehouses', '/admin/units'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/warehouses', ['code' => 'W1', 'name' => 'Warehouse'])->assertForbidden();
        $this->post('/admin/units', ['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc', 'decimal_precision' => 0])->assertForbidden();
    }

    public function test_warehouse_crud_filters_and_api_still_share_write_rules(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/warehouses/create')->assertOk();
        $this->post('/admin/warehouses', [
            'code' => ' wh-main ', 'name' => 'Main Warehouse', 'address_line1' => '1 Street',
            'city' => 'HCM', 'province' => 'HCM', 'country' => 'VN', 'type' => 'distribution',
            'timezone' => 'Asia/Ho_Chi_Minh', 'is_default_sales' => '1', 'is_default_clinic' => '0',
        ])->assertRedirect(route('admin.web.warehouses.index'));
        $warehouse = Warehouse::query()->where('code', 'WH-MAIN')->firstOrFail();
        $this->assertTrue($warehouse->is_default_sales);
        $this->get('/admin/warehouses?search=MAIN&status=active&sort=code')->assertOk()
            ->assertSee('Main Warehouse')->assertSee('distribution')
            ->assertSee(route('admin.web.warehouses.edit', $warehouse));
        $this->get('/admin/warehouses/'.$warehouse->id.'/edit')->assertOk()->assertSee('1 Street');
        $this->from('/admin/warehouses/create')->post('/admin/warehouses', ['code' => 'wh-main', 'name' => 'Duplicate'])
            ->assertRedirect('/admin/warehouses/create')->assertSessionHasErrors('code');
        $this->from('/admin/warehouses/create')->post('/admin/warehouses', ['code' => 'bad code', 'name' => 'Invalid'])
            ->assertSessionHasErrors('code');
        $this->patch('/admin/warehouses/'.$warehouse->id, ['name' => 'Main Revised', 'is_default_sales' => '0'])
            ->assertRedirect(route('admin.web.warehouses.edit', $warehouse));
        $this->assertSame('Main Revised', $warehouse->refresh()->name);
        $this->assertFalse($warehouse->is_default_sales);
        $this->patch('/admin/warehouses/'.$warehouse->id, ['status' => 'inactive'])->assertRedirect();
        $this->get('/admin/warehouses?status=active')->assertOk()->assertDontSee('Main Revised');
        $this->get('/admin/warehouses?status=inactive')->assertOk()->assertSee('Main Revised');
        $this->patch('/admin/warehouses/'.$warehouse->id, ['status' => 'active'])->assertRedirect();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/warehouses?search=WH-MAIN')->assertOk()
            ->assertJsonPath('data.0.name', 'Main Revised');
    }

    public function test_warehouse_default_conflict_and_inventory_history_are_preserved(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Warehouse::factory()->create(['is_default_sales' => true]);
        $this->from('/admin/warehouses/create')->post('/admin/warehouses', [
            'code' => 'WH-CONFLICT', 'name' => 'Second default', 'is_default_sales' => '1',
        ])->assertRedirect('/admin/warehouses/create')->assertSessionHasErrors('code');
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '4', 'operation_key' => (string) Str::uuid(),
        ], $admin->id);
        $this->from(route('admin.web.warehouses.edit', $warehouse))
            ->patch('/admin/warehouses/'.$warehouse->id, ['code' => 'WH-CHANGED'])
            ->assertSessionHasErrors('code');
        $this->assertNotSame('WH-CHANGED', $warehouse->refresh()->code);
        $this->patch('/admin/warehouses/'.$warehouse->id, ['status' => 'inactive'])->assertRedirect();
        $this->get('/admin/warehouses?status=inactive')->assertOk()->assertSee($warehouse->code);
        $this->get('/admin/inventory/'.$variant->id)->assertOk()->assertSee($warehouse->code);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/inventory/receipts', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '1', 'operation_key' => (string) Str::uuid(),
        ])->assertConflict();
    }

    public function test_unit_crud_and_inactive_unit_preserve_existing_variant(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/units', ['code' => ' ml ', 'name' => 'Millilitre', 'symbol' => 'ml', 'decimal_precision' => '3'])
            ->assertRedirect(route('admin.web.units.index'));
        $unit = Unit::query()->where('code', 'ML')->firstOrFail();
        $variant = ProductVariant::factory()->create(['unit_id' => $unit->id]);
        $this->get('/admin/units?search=ML')->assertOk()->assertSee('Millilitre')->assertSee('1</td>', false);
        $this->from('/admin/units/create')->post('/admin/units', ['code' => 'ml', 'name' => 'Duplicate', 'symbol' => 'ml', 'decimal_precision' => 0])
            ->assertSessionHasErrors('code');
        $this->from('/admin/units/create')->post('/admin/units', ['code' => 'BAD', 'name' => 'Bad', 'symbol' => '', 'decimal_precision' => 4])
            ->assertSessionHasErrors(['symbol', 'decimal_precision']);
        $this->patch('/admin/units/'.$unit->id, ['name' => 'Millilitre revised', 'decimal_precision' => 2])
            ->assertRedirect(route('admin.web.units.edit', $unit));
        $this->patch('/admin/units/'.$unit->id, ['status' => 'inactive'])->assertRedirect();
        $this->assertSame($unit->id, $variant->refresh()->unit_id);
        $this->get('/admin/products/'.$variant->product_id.'/edit')->assertOk()
            ->assertSee('Millilitre revised (ml) · ngừng hoạt động, đang sử dụng');
        $this->patch('/admin/products/'.$variant->product_id.'/variants/'.$variant->id, [
            'unit_id' => $unit->id, 'variant_name' => $variant->variant_name, 'sku' => $variant->sku,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/admin/units?status=active')->assertOk()->assertDontSee('Millilitre revised');
        $this->get('/admin/units?status=inactive')->assertOk()->assertSee('Millilitre revised');
        $this->patch('/admin/units/'.$unit->id, ['status' => 'active'])->assertRedirect();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/product-master/units?search=ML')->assertOk()->assertJsonPath('data.0.id', $unit->id);
    }

    public function test_new_products_only_offer_active_units_and_pages_link_to_blade(): void
    {
        $this->withoutVite();
        Unit::factory()->create(['name' => 'Active Measure', 'status' => 'active']);
        $inactive = Unit::factory()->create(['name' => 'Retired Measure', 'status' => 'inactive']);
        $category = ProductCategory::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/products/create')->assertOk()->assertSee('Active Measure')
            ->assertDontSee('Retired Measure')->assertSee(route('admin.web.units.index'));
        $this->from('/admin/products/create')->post('/admin/products', [
            'name' => 'Inactive Unit Product', 'slug' => 'inactive-unit-product',
            'base_sku' => 'INACTIVE-UNIT', 'product_category_id' => $category->id,
            'variants' => [['variant_name' => 'Default', 'unit_id' => $inactive->id,
                'sellable_retail' => '1', 'sellable_dealer' => '0']],
        ])->assertSessionHasErrors('variants.0.unit_id');
        $this->get('/admin/inventory')->assertOk()->assertSee(route('admin.web.warehouses.index'));
        $this->get('/admin/units')->assertOk()->assertSee(route('admin.web.warehouses.index'));
    }

    public function test_warehouse_and_unit_lists_bound_relation_queries(): void
    {
        $this->withoutVite();
        Warehouse::factory()->count(8)->create();
        Unit::factory()->count(8)->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select')) {
                $queries[] = strtolower($query->sql);
            }
        });
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/warehouses')->assertOk();
        $this->get('/admin/units')->assertOk();
        $this->assertLessThanOrEqual(3, count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `inventory_balances`'))));
        $this->assertLessThanOrEqual(3, count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `product_variants`'))));
    }
}
