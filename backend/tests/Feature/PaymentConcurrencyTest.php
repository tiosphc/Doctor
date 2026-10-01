<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
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

    private function order(): SalesOrder
    {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create();

        return SalesOrder::create(['order_code' => 'ORD'.Str::upper(Str::random(12)),
            'creation_operation_key' => (string) Str::uuid(), 'creation_fingerprint' => hash('sha256', (string) Str::uuid()),
            'sales_channel' => 'retail', 'order_source' => 'admin', 'buyer_user_id' => $user->id,
            'warehouse_id' => $warehouse->id, 'currency' => 'VND', 'recipient_name' => 'Buyer',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN', 'order_status' => 'confirmed',
            'payment_status' => 'unpaid', 'fulfillment_status' => 'reserved', 'pricing_context_snapshot' => 'retail',
            'subtotal' => '100.00', 'grand_total' => '100.00',
            'price_resolution_fingerprint' => hash('sha256', (string) Str::uuid()), 'created_by' => $admin->id]);
    }

    /** @param array<string, string> $body */
    private function paymentProcess(SalesOrder $order, array $body): Process
    {
        $args = var_export([$order->id, $order->created_by, $body], true);

        return $this->process('try { [$orderId, $actorId, $body] = '.$args.'; '
            .'$payment = $app->make(\App\Services\PaymentService::class)->recordSettledPayment('
            .'\App\Models\SalesOrder::findOrFail($orderId), $body, $actorId); '
            .'echo json_encode(["result" => "ok", "id" => $payment->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["result" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }');
    }

    /** @param array<string, string> $first @param array<string, string> $second @return list<array<string, mixed>> */
    private function race(SalesOrder $order, array $first, array $second): array
    {
        $blocker = $this->process('\Illuminate\Support\Facades\DB::transaction(function () { '
            .'\Illuminate\Support\Facades\DB::table("sales_orders")->where("id", '.(int) $order->id.')->lockForUpdate()->first(); '
            .'echo "locked"; flush(); usleep(1000000); });');
        $blocker->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($blocker->getOutput(), 'locked') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('locked', $blocker->getOutput(), $blocker->getErrorOutput());
        $left = $this->paymentProcess($order, $first);
        $right = $this->paymentProcess($order, $second);
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

    public function test_concurrent_full_payments_cannot_overpay(): void
    {
        $order = $this->order();
        $results = $this->race($order,
            ['operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash'],
            ['operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash']);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'ok'));
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'payment_status' => 'paid']);
    }

    public function test_concurrent_partial_payments_cannot_exceed_outstanding(): void
    {
        $order = $this->order();
        $results = $this->race($order,
            ['operation_key' => (string) Str::uuid(), 'amount' => '70.00', 'payment_method' => 'cash'],
            ['operation_key' => (string) Str::uuid(), 'amount' => '70.00', 'payment_method' => 'cash']);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'ok'));
        $this->assertContains('PAYMENT_EXCEEDS_OUTSTANDING', array_column($results, 'code'));
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'payment_status' => 'partially_paid']);
    }

    public function test_concurrent_replay_uses_one_payment(): void
    {
        $order = $this->order();
        $body = ['operation_key' => (string) Str::uuid(), 'amount' => '30.00', 'payment_method' => 'cash'];
        $results = $this->race($order, $body, $body);
        $this->assertSame('ok', $results[0]['result']);
        $this->assertSame('ok', $results[1]['result']);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_concurrent_duplicate_external_reference_is_rejected(): void
    {
        $order = $this->order();
        $results = $this->race($order,
            ['operation_key' => (string) Str::uuid(), 'amount' => '30.00', 'payment_method' => 'bank_transfer', 'external_reference' => 'TR-1'],
            ['operation_key' => (string) Str::uuid(), 'amount' => '30.00', 'payment_method' => 'bank_transfer', 'external_reference' => ' tr-1 ']);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'ok'));
        $this->assertContains('PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED', array_column($results, 'code'));
        $this->assertDatabaseCount('payments', 1);
    }
}
