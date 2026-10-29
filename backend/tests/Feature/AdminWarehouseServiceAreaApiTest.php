<?php

namespace Tests\Feature;

use App\Models\AdministrativeProvince;
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

class AdminWarehouseServiceAreaApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_admin_can_manage_service_areas(): void
    {
        $warehouse = Warehouse::factory()->create();
        $province = AdministrativeProvince::query()->firstOrFail();
        $payload = ['warehouse_id' => $warehouse->id, 'province_code' => $province->code, 'priority' => 1];

        $this->getJson('/api/admin/warehouse-service-areas')->assertUnauthorized();
        $this->postJson('/api/admin/warehouse-service-areas', $payload)->assertUnauthorized();

        $this->actingAs(User::factory()->customer()->create());
        $this->getJson('/api/admin/warehouse-service-areas')->assertForbidden();
        $this->postJson('/api/admin/warehouse-service-areas', $payload)->assertForbidden();
    }

    public function test_admin_can_create_filter_and_update_priority_through_api(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $otherWarehouse = Warehouse::factory()->create();
        $province = AdministrativeProvince::query()->firstOrFail();

        $this->getJson('/api/admin/warehouse-service-areas/options')->assertOk()
            ->assertJsonFragment(['id' => $warehouse->id, 'code' => $warehouse->code]);

        $this->postJson('/api/admin/warehouse-service-areas', [
            'warehouse_id' => $warehouse->id,
            'province_code' => $province->code,
            'priority' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.warehouse.id', $warehouse->id)
            ->assertJsonPath('data.province.code', $province->code)
            ->assertJsonPath('data.priority', 3);

        $area = WarehouseServiceArea::query()->sole();
        $this->getJson('/api/admin/warehouse-service-areas?warehouse_id='.$warehouse->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $area->id);
        $this->getJson('/api/admin/warehouse-service-areas?warehouse_id='.$otherWarehouse->id)
            ->assertOk()->assertJsonPath('total', 0);

        $this->patchJson('/api/admin/warehouse-service-areas/'.$area->id, ['priority' => 0])
            ->assertOk()->assertJsonPath('data.priority', 0);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'module' => 'WAREHOUSE',
            'action' => 'UPDATE',
            'target_type' => 'WarehouseServiceArea',
            'target_id' => $area->id,
        ]);
        $this->deleteJson('/api/admin/warehouse-service-areas/'.$area->id)->assertMethodNotAllowed();
    }

    public function test_invalid_assignment_and_priority_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $warehouse = Warehouse::factory()->create();
        $province = AdministrativeProvince::query()->firstOrFail();
        $payload = ['warehouse_id' => $warehouse->id, 'province_code' => $province->code, 'priority' => 1];

        $this->postJson('/api/admin/warehouse-service-areas', [...$payload, 'warehouse_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->postJson('/api/admin/warehouse-service-areas', [...$payload, 'priority' => 65536])
            ->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->postJson('/api/admin/warehouse-service-areas', $payload)->assertCreated();
        $this->postJson('/api/admin/warehouse-service-areas', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('province_code');

        $area = WarehouseServiceArea::query()->sole();
        $this->patchJson('/api/admin/warehouse-service-areas/'.$area->id, [
            'priority' => 2,
            'warehouse_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(1, $area->fresh()->priority);
        $this->assertDatabaseCount('warehouse_service_areas', 1);
    }

    public function test_priority_update_changes_allocation_without_changing_inventory_or_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $firstWarehouse = Warehouse::factory()->create();
        $secondWarehouse = Warehouse::factory()->create();
        $province = AdministrativeProvince::query()->firstOrFail();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        foreach ([$firstWarehouse, $secondWarehouse] as $warehouse) {
            app(InventoryService::class)->receive([
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
                'quantity' => '5',
                'operation_key' => (string) Str::uuid(),
            ], $admin->id);
        }
        $firstArea = WarehouseServiceArea::query()->create([
            'warehouse_id' => $firstWarehouse->id,
            'province_code' => $province->code,
            'priority' => 1,
        ]);
        WarehouseServiceArea::query()->create([
            'warehouse_id' => $secondWarehouse->id,
            'province_code' => $province->code,
            'priority' => 2,
        ]);
        $allocator = app(WarehouseAllocationService::class);
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $this->assertSame($firstWarehouse->id, $allocator->allocate($province->code, $items)['actual']->id);
        $movementCount = DB::table('stock_movements')->count();

        $this->patchJson('/api/admin/warehouse-service-areas/'.$firstArea->id, ['priority' => 3])
            ->assertOk()->assertJsonPath('data.priority', 3);
        $this->assertSame($secondWarehouse->id, $allocator->allocate($province->code, $items)['actual']->id);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $firstWarehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand_quantity' => '5.000',
            'reserved_quantity' => '0.000',
        ]);
        $secondWarehouse->update(['status' => 'inactive']);
        $this->assertSame($firstWarehouse->id, $allocator->allocate($province->code, $items)['actual']->id);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('sales_orders', 0);
    }
}
