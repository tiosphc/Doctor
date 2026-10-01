<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerOrderImport;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerOrderImportService;
use App\Services\DealerQuickOrderService;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DealerQuickOrderConcurrencyTest extends TestCase
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
    private function orderProcess(User $user, DealerAccount $account, array $body): Process
    {
        $arguments = var_export([$user->id, $account->id, $body], true);

        return $this->process('try { [$userId, $accountId, $body] = '.$arguments.'; '
            .'$order = $app->make(\App\Services\DealerQuickOrderService::class)->submit('
            .'\App\Models\User::findOrFail($userId), \App\Models\DealerAccount::findOrFail($accountId), $body); '
            .'echo json_encode(["status" => "ok", "id" => $order->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); } '
            .'catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) { '
            .'echo json_encode(["status" => "not_found"]); }');
    }

    private function import(User $user, DealerAccount $account, ProductVariant $variant,
        string $reference): DealerOrderImport
    {
        $import = DealerOrderImport::create(['dealer_account_id' => $account->id,
            'uploaded_by' => $user->id, 'original_filename' => 'test.xlsx',
            'file_hash' => hash('sha256', $reference.Str::uuid()), 'file_size' => 100,
            'row_count' => 1, 'order_count' => 1]);
        $normalized = strtoupper($reference);
        $import->rows()->create(['sheet_row_number' => 2, 'external_reference' => $reference,
            'external_reference_normalized' => $normalized, 'sku_input' => $variant->sku,
            'quantity' => '1', 'recipient' => ['recipient_name' => 'Recipient',
                'recipient_phone' => '0900000000', 'recipient_email' => '',
                'shipping_address_line1' => 'Street', 'shipping_address_line2' => '',
                'shipping_city' => 'HCM', 'shipping_province' => 'HCM',
                'shipping_country' => 'VN', 'shipping_postal_code' => '', 'delivery_note' => ''],
            'validation_errors' => []]);
        $import->groups()->create(['external_reference' => $reference,
            'external_reference_normalized' => $normalized]);

        return app(DealerOrderImportService::class)->revalidate($user, $account, $import);
    }

    private function importProcess(User $user, DealerAccount $account, DealerOrderImport $import): Process
    {
        $arguments = var_export([$user->id, $account->id, $import->id,
            $import->preview_fingerprint, (string) Str::uuid()], true);

        return $this->process('try { [$userId, $accountId, $importId, $fingerprint, $key] = '.$arguments.'; '
            .'$result = $app->make(\App\Services\DealerOrderImportService::class)->confirm('
            .'\App\Models\User::findOrFail($userId), \App\Models\DealerAccount::findOrFail($accountId), '
            .'\App\Models\DealerOrderImport::findOrFail($importId), $fingerprint, $key); '
            .'echo json_encode(["status" => $result->status]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); } '
            .'catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) { '
            .'echo json_encode(["status" => "not_found"]); }');
    }

    /** @return array<string, mixed> */
    private function processResult(Process $process): array
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

    /** @return array{User, DealerAccount, DealerTier, ProductVariant, PriceListItem} */
    private function fixture(string $stock = '1', string $walletAmount = '100000.00'): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        $price = PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        $admin = User::factory()->admin()->create();
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(), 'amount' => $walletAmount, 'method' => 'other_manual'], $admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => $stock,
            'operation_key' => (string) Str::uuid()], $admin->id);

        return [$user, $account, $tier, $variant, $price];
    }

    /** @return array<string, mixed> */
    private function body(User $user, DealerAccount $account, ProductVariant $variant, ?string $key = null): array
    {
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $fingerprint = app(DealerQuickOrderService::class)->review($user, $account, $items)['review_fingerprint'];

        return ['operation_key' => $key ?? (string) Str::uuid(), 'review_fingerprint' => $fingerprint,
            'items' => $items, 'recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN'];
    }

    public function test_two_dealer_accounts_competing_for_last_unit_do_not_oversell(): void
    {
        [$firstUser, $firstAccount, $tier, $variant] = $this->fixture();
        $secondUser = User::factory()->customer()->create();
        $secondAccount = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $secondAccount->id, 'user_id' => $secondUser->id]);
        app(DealerWalletService::class)->recordDeposit($secondAccount, ['operation_key' => (string) Str::uuid(), 'amount' => '100000.00', 'method' => 'other_manual'], User::factory()->admin()->create());
        $first = $this->orderProcess($firstUser, $firstAccount, $this->body($firstUser, $firstAccount, $variant));
        $second = $this->orderProcess($secondUser, $secondAccount, $this->body($secondUser, $secondAccount, $variant));
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame('INSUFFICIENT_STOCK', collect($results)->firstWhere('status', 'conflict')['code']);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('inventory_balances', ['reserved_quantity' => '1.000', 'on_hand_quantity' => '1.000']);
    }

    public function test_double_submit_same_operation_key_creates_one_order(): void
    {
        [$user, $account, , $variant] = $this->fixture('2');
        $body = $this->body($user, $account, $variant);
        $first = $this->orderProcess($user, $account, $body);
        $second = $this->orderProcess($user, $account, $body);
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_concurrent_orders_serialize_wallet_debit_and_cannot_overspend(): void
    {
        [$user, $account, , $variant] = $this->fixture('2', '100.00');
        $first = $this->orderProcess($user, $account, $this->body($user, $account, $variant));
        $second = $this->orderProcess($user, $account, $this->body($user, $account, $variant));
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];

        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame('DEALER_WALLET_INSUFFICIENT_BALANCE', collect($results)->firstWhere('status', 'conflict')['code']);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '0.00']);
    }

    public function test_price_edit_while_submit_waits_requires_new_review(): void
    {
        [$user, $account, , $variant, $price] = $this->fixture('2');
        $body = $this->body($user, $account, $variant);
        $modifier = $this->process('$productId = '.(int) $variant->product_id.'; $priceId = '.(int) $price->id.'; '
            .'\Illuminate\Support\Facades\DB::transaction(function () use ($productId, $priceId) { '
            .'\Illuminate\Support\Facades\DB::table("products")->where("id", $productId)->lockForUpdate()->first(); '
            .'echo "locked\n"; flush(); usleep(300000); '
            .'\Illuminate\Support\Facades\DB::table("price_list_items")->where("id", $priceId)->update(["unit_price" => "120.00"]); });');
        $this->awaitLock($modifier);
        $submit = $this->orderProcess($user, $account, $body);
        $submit->start();
        $result = $this->processResult($submit);
        $modifier->wait();
        $this->assertTrue($modifier->isSuccessful(), $modifier->getErrorOutput());
        $this->assertSame('conflict', $result['status'], json_encode($result));
        $this->assertSame('DEALER_ORDER_CHANGED', $result['code']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_tier_change_and_suspension_while_submit_waits_fail_closed(): void
    {
        [$user, $account, , $variant] = $this->fixture('2');
        $body = $this->body($user, $account, $variant);
        $gold = DealerTier::factory()->create();
        $modifier = $this->process('$accountId = '.(int) $account->id.'; $tierId = '.(int) $gold->id.'; '
            .'\Illuminate\Support\Facades\DB::transaction(function () use ($accountId, $tierId) { '
            .'\Illuminate\Support\Facades\DB::table("dealer_accounts")->where("id", $accountId)->lockForUpdate()->first(); '
            .'echo "locked\n"; flush(); usleep(300000); '
            .'\Illuminate\Support\Facades\DB::table("dealer_accounts")->where("id", $accountId)->update(["current_tier_id" => $tierId]); });');
        $this->awaitLock($modifier);
        $submit = $this->orderProcess($user, $account, $body);
        $submit->start();
        $result = $this->processResult($submit);
        $modifier->wait();
        $this->assertTrue($modifier->isSuccessful(), $modifier->getErrorOutput());
        $this->assertSame('DEALER_ORDER_CHANGED', $result['code']);
        $this->assertDatabaseCount('sales_orders', 0);

    }

    public function test_account_suspension_while_submit_waits_creates_no_order(): void
    {
        [$user, $account, , $variant] = $this->fixture('2');
        $body = $this->body($user, $account, $variant);
        $modifier = $this->process('$accountId = '.(int) $account->id.'; '
            .'\Illuminate\Support\Facades\DB::transaction(function () use ($accountId) { '
            .'\Illuminate\Support\Facades\DB::table("dealer_accounts")->where("id", $accountId)->lockForUpdate()->first(); '
            .'echo "locked\n"; flush(); usleep(300000); '
            .'\Illuminate\Support\Facades\DB::table("dealer_accounts")->where("id", $accountId)->update(["status" => "suspended"]); });');
        $this->awaitLock($modifier);
        $submit = $this->orderProcess($user, $account, $body);
        $submit->start();
        $result = $this->processResult($submit);
        $modifier->wait();
        $this->assertTrue($modifier->isSuccessful(), $modifier->getErrorOutput());
        $this->assertSame('not_found', $result['status']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_two_confirmations_of_one_import_create_one_order(): void
    {
        [$user, $account, , $variant] = $this->fixture('2');
        $import = $this->import($user, $account, $variant, 'PO-001');
        $first = $this->importProcess($user, $account, $import);
        $second = $this->importProcess($user, $account, $import);
        $first->start();
        $second->start();
        $this->processResult($first);
        $this->processResult($second);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_simultaneous_imports_with_same_external_reference_create_one_order(): void
    {
        [$user, $account, , $variant] = $this->fixture('2');
        $firstImport = $this->import($user, $account, $variant, 'PO-001');
        $secondImport = $this->import($user, $account, $variant, 'po-001');
        $first = $this->importProcess($user, $account, $firstImport);
        $second = $this->importProcess($user, $account, $secondImport);
        $first->start();
        $second->start();
        $this->processResult($first);
        $this->processResult($second);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_simultaneous_imports_competing_for_last_stock_do_not_oversell(): void
    {
        [$user, $account, , $variant] = $this->fixture('1');
        $firstImport = $this->import($user, $account, $variant, 'PO-001');
        $secondImport = $this->import($user, $account, $variant, 'PO-002');
        $first = $this->importProcess($user, $account, $firstImport);
        $second = $this->importProcess($user, $account, $secondImport);
        $first->start();
        $second->start();
        $this->processResult($first);
        $this->processResult($second);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('inventory_balances', ['on_hand_quantity' => '1.000',
            'reserved_quantity' => '1.000']);
    }
}
