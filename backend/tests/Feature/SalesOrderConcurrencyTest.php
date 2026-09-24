<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReconciliationService;
use App\Services\InventoryService;
use App\Services\ReservationReconciliationService;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SalesOrderConcurrencyTest extends TestCase
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
            throw new RuntimeException('Concurrency test may only reset the dedicated MySQL testing database.');
        }
    }

    /** @param list<mixed> $arguments */
    private function process(string $method, array $arguments): Process
    {
        $payload = var_export($arguments, true);
        $code = 'require "vendor/autoload.php"; '
            .'$app = require "bootstrap/app.php"; '
            .'$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '
            .'try { $arguments = '.$payload.'; $arguments[0] = \App\Models\SalesOrder::findOrFail($arguments[0]); $order = $app->make(\App\Services\SalesOrderService::class)->'.$method.'(...$arguments); '
            .'echo json_encode(["status" => "ok", "id" => $order->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
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

    private function draft(Warehouse $warehouse, ProductVariant $variant, int $buyerId, int $actorId): int
    {
        return app(SalesOrderService::class)->createDraft([
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyerId, 'warehouse_id' => $warehouse->id, 'currency' => 'VND',
            'recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ], $actorId)->id;
    }

    public function test_mysql_last_stock_retry_double_fulfillment_and_cancel_race_preserve_ledgers(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '3', 'operation_key' => (string) Str::uuid(),
        ], $admin->id);
        $firstId = $this->draft($warehouse, $variant, $buyer->id, $admin->id);
        $secondId = $this->draft($warehouse, $variant, $buyer->id, $admin->id);
        $firstKey = (string) Str::uuid();
        $secondKey = (string) Str::uuid();
        $attempts = $this->concurrently(
            $this->process('confirm', [$firstId, $firstKey, $admin->id]),
            $this->process('confirm', [$secondId, $secondKey, $admin->id]),
        );
        $this->assertSame(['conflict', 'ok'], collect($attempts)->pluck('status')->sort()->values()->all());
        $winnerId = $attempts[0]['status'] === 'ok' ? $firstId : $secondId;
        $winnerKey = $attempts[0]['status'] === 'ok' ? $firstKey : $secondKey;
        $this->assertDatabaseHas('inventory_balances', ['reserved_quantity' => '2.000', 'on_hand_quantity' => '3.000']);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $retry = $this->concurrently(
            $this->process('confirm', [$winnerId, $winnerKey, $admin->id]),
            $this->process('confirm', [$winnerId, $winnerKey, $admin->id]),
        );
        $this->assertSame(['ok', 'ok'], array_column($retry, 'status'));
        $this->assertDatabaseCount('inventory_reservations', 1);
        $itemId = (int) DB::table('sales_order_items')->where('sales_order_id', $winnerId)->value('id');
        $ship = [$itemId => '2'];
        $fulfillments = $this->concurrently(
            $this->process('fulfill', [$winnerId, (string) Str::uuid(), $ship, $admin->id]),
            $this->process('fulfill', [$winnerId, (string) Str::uuid(), $ship, $admin->id]),
        );
        $this->assertSame(['conflict', 'ok'], collect($fulfillments)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('inventory_balances', ['reserved_quantity' => '0.000', 'on_hand_quantity' => '1.000']);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '1', 'operation_key' => (string) Str::uuid(),
        ], $admin->id);
        $raceId = $this->draft($warehouse, $variant, $buyer->id, $admin->id);
        app(SalesOrderService::class)->confirm(SalesOrder::findOrFail($raceId), (string) Str::uuid(), $admin->id);
        $raceItem = (int) DB::table('sales_order_items')->where('sales_order_id', $raceId)->value('id');
        $race = $this->concurrently(
            $this->process('cancel', [$raceId, (string) Str::uuid(), 'Customer requested', $admin->id]),
            $this->process('fulfill', [$raceId, (string) Str::uuid(), [$raceItem => '1'], $admin->id]),
        );
        $this->assertSame(['conflict', 'ok'], collect($race)->pluck('status')->sort()->values()->all());
        $this->assertSame(0, app(ReservationReconciliationService::class)->report()['difference_count']);
        $this->assertSame(0, app(InventoryReconciliationService::class)->report()['mismatched']);
    }
}
