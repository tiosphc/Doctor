<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\RetailCartService;
use App\Services\RetailCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RetailCheckoutConcurrencyTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function checkoutProcess(int $userId, array $body): Process
    {
        $arguments = var_export([$userId, $body], true);

        return $this->process('try { [$userId, $body] = '.$arguments.'; '
            .'$order = $app->make(\App\Services\RetailCheckoutService::class)->checkout($userId, $body); '
            .'echo json_encode(["status" => "ok", "id" => $order->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }');
    }

    private function confirmProcess(int $orderId, int $adminId): Process
    {
        return $this->process('try { '
            .'$order = \App\Models\SalesOrder::query()->findOrFail('.$orderId.'); '
            .'$confirmed = $app->make(\App\Services\SalesOrderService::class)->confirm($order, "'.Str::uuid().'", '.$adminId.'); '
            .'echo json_encode(["status" => "ok", "id" => $confirmed->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }');
    }

    /** @param array<string, mixed> $body */
    private function checkoutResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function awaitLock(Process $process): void
    {
        $process->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($process->getOutput(), 'locked') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('locked', $process->getOutput(), $process->getErrorOutput());
    }

    /** @return array{User, Warehouse, ProductVariant, PriceList, PriceListItem} */
    private function fixture(string $stock = '1'): array
    {
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $price = PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id]);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => $stock,
            'operation_key' => (string) Str::uuid()], $admin->id);

        return [$admin, $warehouse, $variant, $list, $price];
    }

    /** @return array<string, mixed> */
    private function body(int $userId, ProductVariant $variant): array
    {
        app(RetailCartService::class)->add($userId, $variant->id, '1');
        $fingerprint = app(RetailCheckoutService::class)->review($userId)['review_fingerprint'];

        return ['checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_district' => 'District 1', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'payment_method' => 'cod'];
    }

    public function test_two_pending_orders_compete_for_last_stock_at_admin_confirmation(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        [$admin, , $variant] = $this->fixture();
        $first = User::factory()->customer()->create();
        $second = User::factory()->customer()->create();
        $firstProcess = $this->checkoutProcess($first->id, $this->body($first->id, $variant));
        $secondProcess = $this->checkoutProcess($second->id, $this->body($second->id, $variant));
        $firstProcess->start();
        $secondProcess->start();
        $results = [$this->checkoutResult($firstProcess), $this->checkoutResult($secondProcess)];
        $this->assertSame(['ok', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('sales_orders', 2);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventory_balances', ['reserved_quantity' => '0.000', 'on_hand_quantity' => '1.000']);
        $this->assertDatabaseCount('carts', 2);
        $firstConfirmation = $this->confirmProcess($results[0]['id'], $admin->id);
        $secondConfirmation = $this->confirmProcess($results[1]['id'], $admin->id);
        $firstConfirmation->start();
        $secondConfirmation->start();
        $confirmationResults = [$this->checkoutResult($firstConfirmation), $this->checkoutResult($secondConfirmation)];
        $this->assertSame(['conflict', 'ok'], collect($confirmationResults)->pluck('status')->sort()->values()->all());
        $this->assertSame('INSUFFICIENT_STOCK', collect($confirmationResults)->firstWhere('status', 'conflict')['code']);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('inventory_balances', ['reserved_quantity' => '1.000', 'on_hand_quantity' => '1.000']);
    }

    public function test_same_user_double_checkout_reuses_order_and_cart_conversion(): void
    {
        [, , $variant] = $this->fixture('2');
        $buyer = User::factory()->customer()->create();
        $body = $this->body($buyer->id, $variant);
        $first = $this->checkoutProcess($buyer->id, $body);
        $second = $this->checkoutProcess($buyer->id, $body);
        $first->start();
        $second->start();
        $results = [$this->checkoutResult($first), $this->checkoutResult($second)];
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'converted']);
    }

    public function test_price_edit_while_checkout_waits_returns_changed_review(): void
    {
        [, , $variant, $list, $price] = $this->fixture('2');
        $buyer = User::factory()->customer()->create();
        $body = $this->body($buyer->id, $variant);
        $modifier = $this->process('$listId = '.(int) $list->id.'; $priceId = '.(int) $price->id.'; '
            .'\Illuminate\Support\Facades\DB::transaction(function () use ($listId, $priceId) { '
            .'\Illuminate\Support\Facades\DB::table("price_lists")->where("id", $listId)->lockForUpdate()->first(); '
            .'echo "locked\n"; flush(); usleep(300000); '
            .'\Illuminate\Support\Facades\DB::table("price_list_items")->where("id", $priceId)->update(["unit_price" => "130000.00"]); });');
        $this->awaitLock($modifier);
        $checkout = $this->checkoutProcess($buyer->id, $body);
        $checkout->start();
        $result = $this->checkoutResult($checkout);
        $modifier->wait();
        $this->assertTrue($modifier->isSuccessful(), $modifier->getErrorOutput());
        $this->assertSame('CHECKOUT_CHANGED', $result['code']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_cart_edit_while_checkout_waits_returns_stale_fingerprint(): void
    {
        [, , $variant] = $this->fixture('3');
        $buyer = User::factory()->customer()->create();
        $body = $this->body($buyer->id, $variant);
        $itemId = CartItem::query()->firstOrFail()->id;
        $modifier = $this->process('$userId = '.(int) $buyer->id.'; $itemId = '.(int) $itemId.'; '
            .'\Illuminate\Support\Facades\DB::transaction(function () use ($userId, $itemId) { '
            .'\Illuminate\Support\Facades\DB::table("users")->where("id", $userId)->lockForUpdate()->first(); '
            .'echo "locked\n"; flush(); usleep(300000); '
            .'\Illuminate\Support\Facades\DB::table("cart_items")->where("id", $itemId)->update(["quantity" => "2.000"]); });');
        $this->awaitLock($modifier);
        $checkout = $this->checkoutProcess($buyer->id, $body);
        $checkout->start();
        $result = $this->checkoutResult($checkout);
        $modifier->wait();
        $this->assertTrue($modifier->isSuccessful(), $modifier->getErrorOutput());
        $this->assertSame('CHECKOUT_CHANGED', $result['code']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_concurrent_adds_keep_one_cart_line_and_sum_quantities(): void
    {
        [, , $variant] = $this->fixture('3');
        $buyer = User::factory()->customer()->create();
        $code = '$userId = '.(int) $buyer->id.'; $variantId = '.(int) $variant->id.'; '
            .'$cart = $app->make(\App\Services\RetailCartService::class)->add($userId, $variantId, "1"); '
            .'echo json_encode(["id" => $cart["id"], "quantity" => $cart["items"][0]["quantity"]]);';
        $first = $this->process($code);
        $second = $this->process($code);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $variant->id, 'quantity' => '2.000']);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }
}
