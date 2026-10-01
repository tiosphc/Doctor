<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProcurementConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestingDatabase();
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        $this->assertTestingDatabase();
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function assertTestingDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'aesthetic_clinic_testing') {
            throw new \RuntimeException('Concurrency test may only reset the dedicated MySQL testing database.');
        }
    }

    /** @return array{order_id: int, item_id: int, actor_id: int} */
    private function issuedOrder(): array
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $order = $this->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '10', 'unit_price' => '100']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertOk();

        return ['order_id' => $order['id'], 'item_id' => $order['items'][0]['id'], 'actor_id' => $admin->id];
    }

    /** @param array<string, mixed> $payload */
    private function process(string $method, int $orderId, array $payload, int $actorId): Process
    {
        $code = 'require "vendor/autoload.php"; '
            .'$app = require "bootstrap/app.php"; '
            .'$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            .'try { $order = \\App\\Models\\PurchaseOrder::findOrFail('.$orderId.'); '
            .'$result = $app->make(\\App\\Services\\ProcurementService::class)->'.$method.'($order, '.var_export($payload, true).', '.$actorId.'); '
            .'echo json_encode(["status" => "ok", "id" => $result->id]); } '
            .'catch (\\Illuminate\\Http\\Exceptions\\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }';

        return new Process([PHP_BINARY, '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'aesthetic_clinic_testing',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], null, 30);
    }

    /** @return list<array<string, mixed>> */
    private function concurrently(Process $first, Process $second): array
    {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        return [json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR), json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_concurrent_receipts_cannot_over_receive_and_same_key_replays(): void
    {
        $context = $this->issuedOrder();
        $payload = ['operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $context['item_id'], 'quantity' => '7']]];
        $results = $this->concurrently(
            $this->process('receive', $context['order_id'], $payload, $context['actor_id']),
            $this->process('receive', $context['order_id'], [...$payload, 'operation_key' => (string) Str::uuid()], $context['actor_id']),
        );
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '7.000']);
        $this->assertDatabaseCount('stock_movements', 1);

        $sameKey = ['operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $context['item_id'], 'quantity' => '2']]];
        $replays = $this->concurrently(
            $this->process('receive', $context['order_id'], $sameKey, $context['actor_id']),
            $this->process('receive', $context['order_id'], $sameKey, $context['actor_id']),
        );
        $this->assertSame(['ok', 'ok'], array_column($replays, 'status'));
        $this->assertSame($replays[0]['id'], $replays[1]['id']);
        $this->assertDatabaseCount('goods_receipts', 2);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '9.000']);
    }

    public function test_concurrent_returns_cannot_exceed_receipt(): void
    {
        $context = $this->issuedOrder();
        $receipt = $this->postJson("/api/admin/purchase-orders/{$context['order_id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $context['item_id'], 'quantity' => '10']],
        ])->assertCreated()->json('data');
        $payload = ['operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['goods_receipt_item_id' => $receipt['items'][0]['id'], 'quantity' => '7']]];
        $results = $this->concurrently(
            $this->process('returnGoods', $context['order_id'], $payload, $context['actor_id']),
            $this->process('returnGoods', $context['order_id'], [...$payload, 'operation_key' => (string) Str::uuid()], $context['actor_id']),
        );
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '3.000']);
        $this->assertDatabaseCount('stock_movements', 2);
    }
}
