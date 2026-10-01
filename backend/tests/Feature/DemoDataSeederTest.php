<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoProductImageSeeder;
use Database\Seeders\ProductUnitSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unit_seed_corrects_only_legacy_demo_variants_and_preserves_other_choices(): void
    {
        $legacyBox = Unit::factory()->create(['code' => 'DEMO-BOX', 'name' => 'Demo box', 'symbol' => 'box']);
        $customUnit = Unit::factory()->create(['code' => 'CUSTOM', 'name' => 'Custom', 'symbol' => 'custom']);
        $demoProduct = Product::factory()->create(['product_code' => 'DEMO-PRD-01']);
        $legacyVariant = ProductVariant::factory()->create(['product_id' => $demoProduct->id, 'unit_id' => $legacyBox->id]);
        $customVariant = ProductVariant::factory()->create(['product_id' => $demoProduct->id, 'unit_id' => $customUnit->id]);
        $otherVariant = ProductVariant::factory()->create(['unit_id' => $legacyBox->id]);

        $this->seed(ProductUnitSeeder::class);

        $this->assertSame('CHAI', $legacyVariant->fresh()->unit->code);
        $this->assertSame($customUnit->id, $customVariant->fresh()->unit_id);
        $this->assertSame($legacyBox->id, $otherVariant->fresh()->unit_id);
        $this->assertSame(3, ProductVariant::query()->count());
        $this->assertSame(11, Unit::query()->count());

        $this->seed(ProductUnitSeeder::class);

        $this->assertSame(11, Unit::query()->count());
        $this->assertSame('CHAI', $legacyVariant->fresh()->unit->code);
        $this->assertSame($customUnit->id, $customVariant->fresh()->unit_id);
        $this->assertSame($legacyBox->id, $otherVariant->fresh()->unit_id);
    }

    public function test_demo_images_do_not_replace_an_existing_product_image(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create(['product_code' => 'DEMO-PRD-01']);
        $image = ProductImage::factory()->for($product)->create(['path' => 'products/custom.jpg']);

        $this->seed(DemoProductImageSeeder::class);

        $this->assertSame([$image->id], $product->images()->pluck('id')->all());
        Storage::disk('public')->assertMissing('products/demo/DEMO-PRD-01.jpg');
    }

    public function test_seed_is_additive_repeatable_and_ledger_backed(): void
    {
        Storage::fake('public');
        $existing = User::factory()->create(['email' => 'keep@example.com']);
        $existingWarehouse = Warehouse::factory()->create(['code' => 'EXISTING-WH', 'is_default_sales' => true]);
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseHas('users', ['id' => $existing->id, 'email' => 'keep@example.com']);
        $this->assertSame(9, Unit::query()->whereIn('code', ['CAI', 'HOP', 'CHAI', 'LO', 'TUYP', 'GOI', 'MIENG', 'BOM', 'BO'])->count());
        foreach (['DEMO-SKU-01-1' => 'CHAI', 'DEMO-SKU-02-1' => 'TUYP', 'DEMO-SKU-04-1' => 'BO',
            'DEMO-SKU-05-1' => 'MIENG', 'DEMO-SKU-10-1' => 'LO', 'DEMO-SKU-11-1' => 'HOP',
            'DEMO-GIFT-02-1' => 'MIENG'] as $sku => $unitCode) {
            $this->assertSame($unitCode, ProductVariant::query()->where('sku', $sku)->firstOrFail()->unit->code);
        }
        $this->assertDatabaseHas('warehouses', ['id' => $existingWarehouse->id, 'code' => 'EXISTING-WH', 'is_default_sales' => true]);
        $this->assertDatabaseHas('users', ['email' => 'admin@demo.local', 'role' => 'admin']);
        $this->assertSame(16, DB::table('products')->where('product_code', 'like', 'DEMO-%')->count());
        $this->assertSame(16, DB::table('product_images')->where('path', 'like', 'products/demo/%')->count());
        Storage::disk('public')->assertExists('products/demo/DEMO-PRD-01.jpg');
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data.0.images');
        $dealerUser = User::query()->where('email', 'dealer1@demo.local')->firstOrFail();
        $dealerAccountId = DB::table('dealer_account_users')->where('user_id', $dealerUser->id)->value('dealer_account_id');
        Sanctum::actingAs($dealerUser);
        $this->getJson('/api/dealer/accounts/'.$dealerAccountId.'/products')
            ->assertOk()->assertJsonCount(1, 'data.0.images');
        $this->assertSame(33, DB::table('product_variants')->where('sku', 'like', 'DEMO-%')->count());
        $this->assertSame(3, DB::table('products')->where('gift_only', true)->where('can_be_gift', true)->count());
        $this->assertSame(3, DB::table('sales_promotions')->where('discount_type', 'buy_a_get_b')->count());
        $this->assertDatabaseHas('sales_promotion_gift_rules', ['minimum_buy_quantity' => '2.000', 'gift_quantity' => '1.000']);
        $this->assertSame(3, DB::table('stock_movements')->where('reason_detail', 'Demo gift opening stock')->count());
        $this->getJson('/api/gift-promotions')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonFragment(['code' => 'DEMOGIFT-CLEAN', 'gift_available' => true]);
        $this->getJson('/api/products/demo-product-01')->assertOk()
            ->assertJsonPath('data.variants.0.unit_symbol', 'chai');
        $this->assertSame(25, DB::table('sales_orders')->where('sales_channel', 'retail')->count());
        $this->assertSame(16, DB::table('sales_orders')->where('sales_channel', 'dealer')->count());
        $this->assertSame(9, DB::table('purchase_orders')->where('code', 'like', 'DEMO-%')->count());
        $this->assertDatabaseHas('purchase_orders', ['code' => 'DEMO-PO-002', 'status' => 'partially_received']);
        $this->assertGreaterThan(0, DB::table('payments')->where('status', 'settled')->count());
        $this->assertGreaterThan(0, DB::table('refunds')->where('status', 'completed')->count());
        $this->assertSame(5, DB::table('dealer_accounts')->where('legal_name', 'like', 'DEMO %')->count());
        $this->assertSame(5, DB::table('dealer_account_users')->where('membership_role', 'owner')->where('status', 'active')->count());
        $this->assertSame(25, DB::table('appointments')->where('booking_code', 'like', 'DEMO-%')->count());
        $this->assertSame(0, DB::table('sales_orders')->where('payment_status', 'paid')
            ->whereNotIn('id', DB::table('payment_allocations')->pluck('sales_order_id'))->count());
        $this->assertSame(0, DB::table('goods_receipt_items')->whereNull('stock_movement_id')->count());
        $this->assertSame(0, DB::table('purchase_return_items')->whereNull('stock_movement_id')->count());
        $this->assertSame(0, DB::table('dealer_wallets as wallet')->whereRaw('wallet.balance <> (SELECT COALESCE(SUM(CASE WHEN tx.direction = \'credit\' THEN tx.amount ELSE -tx.amount END), 0) FROM dealer_wallet_transactions tx WHERE tx.dealer_wallet_id = wallet.id)')->count());
        $this->assertSame(0, DB::table('inventory_balances')->whereColumn('reserved_quantity', '>', 'on_hand_quantity')->count());
        $this->assertSame(0, DB::table('inventory_balances')->where('on_hand_quantity', '<', 0)->count());
        $this->assertSame(0, SalesOrderItem::query()->where('unit_code_snapshot', 'DEMO-BOX')->count());
        $this->assertGreaterThan(0, DB::table('sales_orders')->whereDate('created_at', now()->toDateString())->count());
        $this->assertGreaterThan(0, DB::table('sales_orders')->where('created_at', '<', now()->subDays(30))->count());
        $this->assertSame(0, DB::table('sales_orders')->where('created_at', '>', now())->count());

        $orderItem = SalesOrderItem::query()->where('product_code_snapshot', 'DEMO-PRD-01')->firstOrFail();
        $amounts = $orderItem->only(['quantity', 'unit_price_snapshot', 'line_total']);
        $orderItem->update(['unit_code_snapshot' => 'DEMO-BOX', 'unit_name_snapshot' => 'Demo box']);
        $this->seed(ProductUnitSeeder::class);
        $this->assertSame('CHAI', $orderItem->fresh()->unit_code_snapshot);
        $this->assertSame('Chai', $orderItem->fresh()->unit_name_snapshot);
        $this->assertSame($amounts, $orderItem->fresh()->only(['quantity', 'unit_price_snapshot', 'line_total']));

        $tables = ['users', 'appointments', 'products', 'product_variants', 'product_images', 'sales_promotions', 'sales_promotion_gift_rules', 'sales_orders', 'payments', 'refunds',
            'purchase_orders', 'goods_receipts', 'purchase_returns', 'dealer_wallet_transactions', 'stock_movements', 'price_list_items', 'inventory_balances', 'units'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->seed(DemoDataSeeder::class);
        foreach ($tables as $table) {
            $this->assertSame($counts[$table], DB::table($table)->count(), $table.' duplicated on replay');
        }
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'email' => 'keep@example.com']);
    }
}
