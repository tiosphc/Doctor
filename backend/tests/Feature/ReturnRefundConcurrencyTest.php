<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReturnRefundConcurrencyTest extends TestCase
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

    private function process(string $code): Process
    {
        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '.$code],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'aesthetic_clinic_testing',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 30);
    }

    /** @return array{int, int, int} */
    private function order(bool $fulfilled): array
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        DB::table('units')->where('id', $variant->unit_id)->update(['decimal_precision' => 1]);
        $list = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $id = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => User::factory()->customer()->create()->id,
            'warehouse_id' => $warehouse->id, 'currency' => 'VND', 'recipient_name' => 'Buyer',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $itemId = DB::table('sales_order_items')->where('sales_order_id', $id)->value('id');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        if ($fulfilled) {
            $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
                'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
            ])->assertOk();
        }

        return [$id, $itemId, $admin->id];
    }

    /** @param array<string, mixed> $body */
    private function operation(string $type, int $orderId, int $actorId, array $body): Process
    {
        $args = var_export([$orderId, $actorId, $body], true);
        $call = match ($type) {
            'refund' => '$app->make(\App\Services\RefundService::class)->complete($order, $body, $actorId)',
            'return' => '$app->make(\App\Services\SalesReturnService::class)->complete($order, $body, $actorId)',
            'cancel' => '$app->make(\App\Services\SalesOrderService::class)->cancel($order, $body["operation_key"], $body["reason"], $actorId)',
        };

        return $this->process('try { [$orderId, $actorId, $body] = '.$args.'; '
            .'$order = \App\Models\SalesOrder::findOrFail($orderId); $result = '.$call.'; '
            .'echo json_encode(["result" => "ok", "id" => $result->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["result" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }');
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second @return list<array<string, mixed>> */
    private function race(int $orderId, int $actorId, string $firstType, array $first, string $secondType, array $second): array
    {
        $blocker = $this->process('\Illuminate\Support\Facades\DB::transaction(function () { '
            .'\Illuminate\Support\Facades\DB::table("sales_orders")->where("id", '.(int) $orderId.')->lockForUpdate()->first(); '
            .'echo "locked"; flush(); usleep(1000000); });');
        $blocker->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($blocker->getOutput(), 'locked') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('locked', $blocker->getOutput(), $blocker->getErrorOutput());
        $left = $this->operation($firstType, $orderId, $actorId, $first);
        $right = $this->operation($secondType, $orderId, $actorId, $second);
        $left->start();
        $right->start();
        $blocker->wait();
        $left->wait();
        $right->wait();
        $this->assertTrue($left->isSuccessful(), $left->getErrorOutput());
        $this->assertTrue($right->isSuccessful(), $right->getErrorOutput());

        return [json_decode($left->getOutput(), true, flags: JSON_THROW_ON_ERROR),
            json_decode($right->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_two_refunds_cannot_exceed_settled_money(): void
    {
        [$id, , $actorId] = $this->order(false);
        $body = ['amount' => '150.00', 'refund_method' => 'cash', 'reason' => 'other'];
        $results = $this->race($id, $actorId, 'refund', [...$body, 'operation_key' => (string) Str::uuid()],
            'refund', [...$body, 'operation_key' => (string) Str::uuid()]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'ok'));
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_same_refund_key_is_one_financial_effect(): void
    {
        [$id, , $actorId] = $this->order(false);
        $body = ['amount' => '100.00', 'refund_method' => 'cash', 'reason' => 'other',
            'operation_key' => (string) Str::uuid()];
        $results = $this->race($id, $actorId, 'refund', $body, 'refund', $body);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_two_returns_cannot_exceed_fulfilled_quantity_or_double_restock(): void
    {
        [$id, $itemId, $actorId] = $this->order(true);
        $body = ['reason' => 'Returned', 'items' => [['item_id' => $itemId,
            'quantity' => '1.5', 'restock_quantity' => '1.5']]];
        $results = $this->race($id, $actorId, 'return', [...$body, 'operation_key' => (string) Str::uuid()],
            'return', [...$body, 'operation_key' => (string) Str::uuid()]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'ok'));
        $this->assertDatabaseCount('sales_returns', 1);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_same_return_key_is_one_inventory_effect(): void
    {
        [$id, $itemId, $actorId] = $this->order(true);
        $body = ['operation_key' => (string) Str::uuid(), 'reason' => 'Returned',
            'items' => [['item_id' => $itemId, 'quantity' => '1', 'restock_quantity' => '1']]];
        $results = $this->race($id, $actorId, 'return', $body, 'return', $body);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('sales_returns', 1);
    }

    public function test_refund_and_cancellation_race_never_cancels_with_money_retained(): void
    {
        [$id, , $actorId] = $this->order(false);
        $results = $this->race($id, $actorId, 'refund', [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00',
            'refund_method' => 'cash', 'reason' => 'order_cancel',
        ], 'cancel', ['operation_key' => (string) Str::uuid(), 'reason' => 'Customer request']);
        $order = DB::table('sales_orders')->where('id', $id)->first();
        if ($order->order_status === 'cancelled') {
            $this->assertDatabaseHas('sales_orders', ['id' => $id, 'refund_status' => 'fully_refunded']);
        } else {
            $this->assertContains('PAID_ORDER_REQUIRES_REFUND', array_column($results, 'code'));
        }
    }
}
