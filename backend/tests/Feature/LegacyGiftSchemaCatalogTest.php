<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class LegacyGiftSchemaCatalogTest extends TestCase
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

    public function test_product_catalogs_remain_readable_before_gift_migration(): void
    {
        $migration = require database_path('migrations/2026_09_28_102009_add_buy_a_get_b_sales_promotions.php');
        $migration->down();
        DB::table('migrations')->where('migration', '2026_09_28_102009_add_buy_a_get_b_sales_promotions')->delete();
        $this->assertFalse(Schema::hasColumn('products', 'gift_only'));
        $this->assertFalse(Schema::hasTable('sales_promotion_gift_rules'));

        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_retail' => true, 'sellable_dealer' => true]);
        $retail = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $retail->id,
            'product_variant_id' => $variant->id, 'unit_price' => '300.00']);
        $dealer = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id]);
        PriceListItem::factory()->create(['price_list_id' => $dealer->id,
            'product_variant_id' => $variant->id, 'unit_price' => '200.00']);

        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gift_promotions', []);
        $this->getJson("/api/products/{$variant->product->slug}")->assertOk()
            ->assertJsonPath('data.retail_price.unit_price', '300.00');
        $this->getJson('/api/gift-promotions')->assertOk()->assertJsonPath('data', []);

        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}";
        $this->getJson("$base/products")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.variants.0.dealer_price.unit_price', '200.00');
        $this->getJson("$base/products/{$variant->product->slug}")->assertOk()
            ->assertJsonPath('data.gift_promotions', []);
        $this->getJson("$base/gift-promotions")->assertOk()->assertJsonPath('data', []);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/products?gift_filter=gift_only')->assertOk()->assertJsonPath('total', 0);
    }

    private function assertTestingDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'aesthetic_clinic_testing') {
            throw new RuntimeException('Legacy-schema test may only reset the dedicated MySQL testing database.');
        }
    }
}
