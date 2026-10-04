<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerOrderImport;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
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

class SalesPromotionConcurrencyTest extends TestCase
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

    private function process(User $user, DealerAccount $account, ProductVariant $variant, ?string $code): Process
    {
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $shipping = $this->shipping();
        $review = app(DealerQuickOrderService::class)->review($user, $account, $items, $shipping);
        $body = ['operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => $items, ...$shipping];
        $arguments = var_export([$user->id, $account->id, $body], true);

        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; '
            .'$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '
            .'try { [$userId, $accountId, $body] = '.$arguments.'; '
            .'$order = $app->make(\App\Services\DealerQuickOrderService::class)->submit('
            .'\App\Models\User::findOrFail($userId), \App\Models\DealerAccount::findOrFail($accountId), $body); '
            .'echo json_encode(["status" => "ok", "id" => $order->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'aesthetic_clinic_testing',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 30);
    }

    /** @return array<string, string> */
    private function shipping(): array
    {
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();

        return ['recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_province_code' => '79',
            'shipping_ward_code' => $ward->code];
    }

    private function importProcess(User $user, DealerAccount $account, DealerOrderImport $import): Process
    {
        $arguments = var_export([$user->id, $account->id, $import->id,
            $import->preview_fingerprint, (string) Str::uuid()], true);

        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; '
            .'$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '
            .'try { [$userId, $accountId, $importId, $fingerprint, $key] = '.$arguments.'; '
            .'$result = $app->make(\App\Services\DealerOrderImportService::class)->confirm('
            .'\App\Models\User::findOrFail($userId), \App\Models\DealerAccount::findOrFail($accountId), '
            .'\App\Models\DealerOrderImport::findOrFail($importId), $fingerprint, $key); '
            .'echo json_encode(["status" => $result->status]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { '
            .'echo json_encode(["status" => "conflict", "code" => $exception->getResponse()->getData(true)["code"]]); }'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'aesthetic_clinic_testing',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 30);
    }

    public function test_last_promotion_usage_is_atomic_across_two_dealer_accounts(): void
    {
        $tier = DealerTier::factory()->create();
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '2', 'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'percentage',
            'discount_value' => '10.00', 'sales_scope' => 'dealer', 'total_usage_limit' => 1]);
        $processes = [];
        foreach (range(1, 2) as $index) {
            $user = User::factory()->customer()->create();
            $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
            DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
            app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
                'amount' => '100.00', 'method' => 'other_manual'], $admin);
            $processes[] = $this->process($user, $account, $variant, $promotion->code);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertContains(collect($results)->firstWhere('status', 'conflict')['code'],
            ['DEALER_ORDER_CHANGED', 'PROMOTION_USAGE_LIMIT_REACHED']);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_promotion_redemptions', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);

    }

    public function test_excel_and_quick_order_compete_for_one_promotion_use_without_duplicate_financial_writes(): void
    {
        $tier = DealerTier::factory()->create();
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '2', 'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'percentage',
            'discount_value' => '10.00', 'sales_scope' => 'dealer', 'total_usage_limit' => 1]);
        $accounts = [];
        foreach (range(1, 2) as $index) {
            $user = User::factory()->customer()->create();
            $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
            DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
            app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
                'amount' => '100.00', 'method' => 'other_manual'], $admin);
            $accounts[] = [$user, $account];
        }
        [$quickUser, $quickAccount] = $accounts[0];
        [$importUser, $importAccount] = $accounts[1];
        $import = DealerOrderImport::create(['dealer_account_id' => $importAccount->id,
            'uploaded_by' => $importUser->id, 'original_filename' => 'promotion.xlsx',
            'file_hash' => hash('sha256', (string) Str::uuid()), 'file_size' => 100,
            'row_count' => 1, 'order_count' => 1]);
        $import->rows()->create(['sheet_row_number' => 2, 'external_reference' => 'PO-PROMO',
            'external_reference_normalized' => 'PO-PROMO', 'sku_input' => $variant->sku,
            'quantity' => '1', 'promotion_code' => null,
            'recipient' => ['recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
                'recipient_email' => '', 'shipping_address_line1' => 'Street', 'shipping_address_line2' => '',
                'shipping_province_code' => '79',
                'shipping_ward_code' => AdministrativeWard::query()->where('province_code', '79')->firstOrFail()->code,
                'shipping_postal_code' => '', 'delivery_note' => ''], 'validation_errors' => []]);
        $import->groups()->create(['external_reference' => 'PO-PROMO', 'external_reference_normalized' => 'PO-PROMO']);
        $import = app(DealerOrderImportService::class)->revalidate($importUser, $importAccount, $import);
        $this->assertSame('preview_ready', $import->status);
        $quick = $this->process($quickUser, $quickAccount, $variant, $promotion->code);
        $excel = $this->importProcess($importUser, $importAccount, $import);
        $quick->start();
        $excel->start();
        foreach ([$quick, $excel] as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_promotion_redemptions', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('payments', ['amount' => '90.00', 'status' => 'settled']);
    }

    public function test_two_dealer_orders_cannot_reserve_the_last_gift_unit(): void
    {
        $tier = DealerTier::factory()->create();
        $paid = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $paid->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        $admin = User::factory()->admin()->create();
        foreach ([$paid->id => '2', $gift->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId, 'quantity' => $quantity,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'buy_a_get_b',
            'discount_value' => '0.00', 'sales_scope' => 'dealer']);
        $promotion->giftRule()->create(['buy_product_id' => $paid->product_id,
            'buy_variant_id' => $paid->id, 'minimum_buy_quantity' => '1.000',
            'gift_product_id' => $gift->product_id, 'gift_variant_id' => $gift->id,
            'gift_quantity' => '1.000', 'repeat_per_multiple' => false]);
        $processes = [];
        foreach (range(1, 2) as $index) {
            $user = User::factory()->customer()->create();
            $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
            DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
            app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
                'amount' => '100.00', 'method' => 'other_manual'], $admin);
            $processes[] = $this->process($user, $account, $paid, $promotion->code);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertContains(collect($results)->firstWhere('status', 'conflict')['code'],
            ['PROMOTION_GIFT_OUT_OF_STOCK', 'DEALER_ORDER_CHANGED']);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_promotion_redemptions', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('inventory_reservations', 2);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $gift->id, 'on_hand_quantity' => '1.000',
            'reserved_quantity' => '1.000']);
    }

    public function test_normal_sale_and_gift_compete_for_the_same_last_sku_unit(): void
    {
        $tier = DealerTier::factory()->create();
        $buy = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $shared = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $shared->product->update(['can_be_gift' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        foreach ([$buy, $shared] as $variant) {
            PriceListItem::factory()->create(['price_list_id' => $list->id,
                'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        }
        $admin = User::factory()->admin()->create();
        foreach ([$buy->id => '1', $shared->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId, 'quantity' => $quantity,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'buy_a_get_b',
            'discount_value' => '0.00', 'sales_scope' => 'dealer']);
        $promotion->giftRule()->create(['buy_product_id' => $buy->product_id,
            'buy_variant_id' => $buy->id, 'minimum_buy_quantity' => '1.000',
            'gift_product_id' => $shared->product_id, 'gift_variant_id' => $shared->id,
            'gift_quantity' => '1.000', 'repeat_per_multiple' => false]);
        $processes = [];
        foreach ([[$buy, $promotion->code], [$shared, null]] as [$variant, $code]) {
            $user = User::factory()->customer()->create();
            $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
            DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
            app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
                'amount' => '100.00', 'method' => 'other_manual'], $admin);
            $processes[] = $this->process($user, $account, $variant, $code);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $shared->id, 'on_hand_quantity' => '1.000',
            'reserved_quantity' => '1.000']);
    }
}
