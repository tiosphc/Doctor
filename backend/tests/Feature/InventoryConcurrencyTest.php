<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
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

    /** @param array<string, mixed> $data */
    private function process(string $method, array $data, int $actorId): Process
    {
        $payload = var_export($data, true);
        $code = 'require "vendor/autoload.php"; '
            .'$app = require "bootstrap/app.php"; '
            .'$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            .'try { $movement = $app->make(\\App\\Services\\InventoryService::class)->'.$method.'('.$payload.', '.$actorId.'); '
            .'echo json_encode(["status" => "ok", "id" => $movement->id]); } '
            .'catch (\\Illuminate\\Http\\Exceptions\\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }';

        return new Process([PHP_BINARY, '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'aesthetic_clinic_testing', 'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
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

        return [json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR),
            json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_mysql_concurrent_receipts_adjustments_and_retries_preserve_one_ledger(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $base = ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id];

        $receipts = $this->concurrently(
            $this->process('receive', [...$base, 'quantity' => '2', 'operation_key' => (string) Str::uuid()], $admin->id),
            $this->process('receive', [...$base, 'quantity' => '3', 'operation_key' => (string) Str::uuid()], $admin->id),
        );
        $this->assertSame(['ok', 'ok'], array_column($receipts, 'status'));
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => '5.000']);
        $this->assertDatabaseCount('inventory_balances', 1);
        $this->assertDatabaseCount('stock_movements', 2);

        $adjustments = $this->concurrently(
            $this->process('adjust', [...$base, 'quantity' => '-4', 'reason_code' => 'COUNT', 'reason_detail' => 'Count correction', 'operation_key' => (string) Str::uuid()], $admin->id),
            $this->process('adjust', [...$base, 'quantity' => '-4', 'reason_code' => 'COUNT', 'reason_detail' => 'Count correction', 'operation_key' => (string) Str::uuid()], $admin->id),
        );
        $this->assertSame(['conflict', 'ok'], collect($adjustments)->pluck('status')->sort()->values()->all());
        $this->assertContains('INSUFFICIENT_AVAILABLE_STOCK', array_column($adjustments, 'code'));
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '1.000']);
        $this->assertDatabaseCount('stock_movements', 3);

        $nonCompetingAdjustments = $this->concurrently(
            $this->process('adjust', [...$base, 'quantity' => '2', 'reason_code' => 'COUNT', 'reason_detail' => 'Count correction', 'operation_key' => (string) Str::uuid()], $admin->id),
            $this->process('adjust', [...$base, 'quantity' => '-1', 'reason_code' => 'COUNT', 'reason_detail' => 'Count correction', 'operation_key' => (string) Str::uuid()], $admin->id),
        );
        $this->assertSame(['ok', 'ok'], array_column($nonCompetingAdjustments, 'status'));
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '2.000']);
        $this->assertDatabaseCount('stock_movements', 5);

        $same = [...$base, 'quantity' => '2', 'operation_key' => (string) Str::uuid()];
        $retries = $this->concurrently($this->process('receive', $same, $admin->id), $this->process('receive', $same, $admin->id));
        $this->assertSame(['ok', 'ok'], array_column($retries, 'status'));
        $this->assertSame($retries[0]['id'], $retries[1]['id']);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '4.000']);
        $this->assertDatabaseCount('stock_movements', 6);
    }
}
