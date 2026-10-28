<?php

namespace Tests\Feature;

use App\Models\AdministrativeProvince;
use App\Models\AuditLog;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\InventoryService;
use App\Services\WarehouseAllocationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeWarehouseServiceAreaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{Warehouse, AdministrativeProvince} */
    private function fixture(): array
    {
        return [Warehouse::factory()->create(), AdministrativeProvince::query()->firstOrFail()];
    }

    public function test_routes_require_admin_and_offer_no_delete_operation(): void
    {
        [$warehouse, $province] = $this->fixture();
        $area = WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id,
            'province_code' => $province->code, 'priority' => 1]);
        $index = route('admin.web.warehouse-service-areas.index');
        $create = route('admin.web.warehouse-service-areas.create');
        $store = route('admin.web.warehouse-service-areas.store');
        $edit = route('admin.web.warehouse-service-areas.edit', $area);
        $update = route('admin.web.warehouse-service-areas.update', $area);

        $this->get($index)->assertRedirect(route('login'));
        $this->get($create)->assertRedirect(route('login'));
        $this->post($store, [])->assertRedirect(route('login'));
        $this->get($edit)->assertRedirect(route('login'));
        $this->patch($update, ['priority' => 2])->assertRedirect(route('login'));

        $this->actingAs(User::factory()->customer()->create());
        $this->get($index)->assertForbidden();
        $this->get($create)->assertForbidden();
        $this->post($store, [])->assertForbidden();
        $this->get($edit)->assertForbidden();
        $this->patch($update, ['priority' => 2])->assertForbidden();
        $this->delete($update)->assertMethodNotAllowed();
        $dealerUser = User::factory()->customer()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => DealerAccount::factory()->create()->id,
            'user_id' => $dealerUser->id]);
        $this->actingAs($dealerUser);
        $this->post($store, ['warehouse_id' => $warehouse->id, 'province_code' => $province->code,
            'priority' => 1])->assertForbidden();
    }

    public function test_admin_can_create_filter_and_update_priority_with_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [$warehouse, $province] = $this->fixture();
        $otherWarehouse = Warehouse::factory()->create();
        $this->get(route('admin.web.warehouse-service-areas.create'))->assertOk()
            ->assertSee($warehouse->name)->assertSee($province->name);

        $this->post(route('admin.web.warehouse-service-areas.store'), [
            'warehouse_id' => $warehouse->id, 'province_code' => $province->code, 'priority' => 3,
        ])->assertRedirect(route('admin.web.warehouse-service-areas.index'));
        $area = WarehouseServiceArea::query()->sole();
        $this->assertSame(3, $area->priority);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'module' => 'WAREHOUSE',
            'action' => 'CREATE', 'target_type' => 'WarehouseServiceArea', 'target_id' => $area->id]);

        $this->get(route('admin.web.warehouse-service-areas.index', ['warehouse_id' => $warehouse->id,
            'province_code' => $province->code]))->assertOk()->assertSee($warehouse->name)
            ->assertSee(route('admin.web.warehouse-service-areas.edit', $area));
        $filtered = $this->get(route('admin.web.warehouse-service-areas.index', ['warehouse_id' => $otherWarehouse->id]));
        $filtered->assertOk();
        $this->assertSame(0, $filtered->viewData('areas')->total());

        $this->get(route('admin.web.warehouse-service-areas.edit', $area))->assertOk()
            ->assertSee($warehouse->name)->assertSee($province->name);
        $this->patch(route('admin.web.warehouse-service-areas.update', $area), ['priority' => 0])
            ->assertRedirect(route('admin.web.warehouse-service-areas.edit', $area));
        $this->assertSame(0, $area->fresh()->priority);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'module' => 'WAREHOUSE',
            'action' => 'UPDATE', 'target_type' => 'WarehouseServiceArea', 'target_id' => $area->id]);
        $count = AuditLog::query()->where('target_type', 'WarehouseServiceArea')->count();
        $this->patch(route('admin.web.warehouse-service-areas.update', $area), ['priority' => 0])
            ->assertRedirect();
        $this->assertSame($count, AuditLog::query()->where('target_type', 'WarehouseServiceArea')->count());
    }

    public function test_duplicate_invalid_province_warehouse_and_priority_cannot_change_areas(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$warehouse, $province] = $this->fixture();
        $valid = ['warehouse_id' => $warehouse->id, 'province_code' => $province->code, 'priority' => 1];
        $route = route('admin.web.warehouse-service-areas.store');
        $this->post($route, [...$valid, 'warehouse_id' => 999999])->assertSessionHasErrors('warehouse_id');
        $this->post($route, [...$valid, 'province_code' => 'MISSING'])->assertSessionHasErrors('province_code');
        $this->post($route, [...$valid, 'priority' => -1])->assertSessionHasErrors('priority');
        $this->post($route, [...$valid, 'priority' => 65536])->assertSessionHasErrors('priority');
        $this->assertDatabaseCount('warehouse_service_areas', 0);

        $this->post($route, $valid)->assertRedirect();
        $area = WarehouseServiceArea::query()->sole();
        $this->post($route, $valid)->assertSessionHasErrors('province_code');
        $this->patch(route('admin.web.warehouse-service-areas.update', $area), [
            'priority' => 4, 'province_code' => '79'])->assertSessionHasErrors('province_code');
        $this->patch(route('admin.web.warehouse-service-areas.update', $area), [
            'priority' => 4, 'warehouse_id' => 999999])->assertSessionHasErrors('warehouse_id');
        $this->patch(route('admin.web.warehouse-service-areas.update', $area), ['priority' => 65536])
            ->assertSessionHasErrors('priority');
        $this->assertSame(1, $area->fresh()->priority);
        $this->assertDatabaseCount('warehouse_service_areas', 1);
    }

    public function test_list_searches_and_paginates_assignments_with_filter_preserved(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$warehouse, $province] = $this->fixture();
        $otherWarehouse = Warehouse::factory()->create();
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id,
            'province_code' => $province->code, 'priority' => 1]);
        $provinces = AdministrativeProvince::query()->where('code', '!=', $province->code)->limit(21)->get();
        foreach ($provinces as $entry) {
            WarehouseServiceArea::query()->create(['warehouse_id' => $otherWarehouse->id,
                'province_code' => $entry->code, 'priority' => 1]);
        }

        $search = $this->get(route('admin.web.warehouse-service-areas.index', ['search' => $warehouse->code]));
        $search->assertOk();
        $this->assertSame(1, $search->viewData('areas')->total());
        $this->assertSame($warehouse->id, $search->viewData('areas')->first()->warehouse_id);

        $page = $this->get(route('admin.web.warehouse-service-areas.index', ['warehouse_id' => $otherWarehouse->id]));
        $page->assertOk()->assertSee('page=2')->assertSee('warehouse_id='.$otherWarehouse->id);
        $this->assertSame(21, $page->viewData('areas')->total());
        $this->assertSame(20, $page->viewData('areas')->count());
    }

    public function test_priority_change_affects_new_allocation_without_changing_stock_or_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [$firstWarehouse, $province] = $this->fixture();
        $secondWarehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        foreach ([$firstWarehouse, $secondWarehouse] as $warehouse) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id, 'quantity' => '5',
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $first = WarehouseServiceArea::query()->create(['warehouse_id' => $firstWarehouse->id,
            'province_code' => $province->code, 'priority' => 1]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $secondWarehouse->id,
            'province_code' => $province->code, 'priority' => 2]);
        $allocation = app(WarehouseAllocationService::class);
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $this->assertSame($firstWarehouse->id, $allocation->allocate($province->code, $items)['actual']->id);
        $movementCount = DB::table('stock_movements')->count();

        $this->patch(route('admin.web.warehouse-service-areas.update', $first), ['priority' => 3])
            ->assertRedirect();
        $this->assertSame($secondWarehouse->id, $allocation->allocate($province->code, $items)['actual']->id);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $firstWarehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000']);
        $secondWarehouse->update(['status' => 'inactive']);
        $this->assertSame($firstWarehouse->id, $allocation->allocate($province->code, $items)['actual']->id);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('sales_orders', 0);
    }
}
