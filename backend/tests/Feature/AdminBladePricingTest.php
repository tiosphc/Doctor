<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\DealerPricingService;
use App\Services\ProductPricingService;
use App\Services\RetailPricingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminBladePricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pricing_pages_and_writes_require_admin(): void
    {
        $variant = ProductVariant::factory()->create();
        $tier = DealerTier::factory()->create();

        foreach (['/admin/prices/retail', '/admin/prices/dealer'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->patch('/admin/prices/retail/'.$variant->id, ['unit_price' => 100])->assertRedirect('/login');
        $this->put('/admin/prices/dealer/'.$variant->id.'/'.$tier->id, ['unit_price' => 90, 'minimum_quantity' => 1])->assertRedirect('/login');
        $this->delete('/admin/prices/dealer/'.$variant->id.'/'.$tier->id)->assertRedirect('/login');

        $this->actingAs(User::factory()->customer()->create());
        foreach (['/admin/prices/retail', '/admin/prices/dealer'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->patch('/admin/prices/retail/'.$variant->id, ['unit_price' => 100])->assertForbidden();
        $this->put('/admin/prices/dealer/'.$variant->id.'/'.$tier->id, ['unit_price' => 90, 'minimum_quantity' => 1])->assertForbidden();
        $this->delete('/admin/prices/dealer/'.$variant->id.'/'.$tier->id)->assertForbidden();
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_retail_list_searches_filters_and_paginates_sku_prices(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create(['name' => 'Serum Alpha', 'product_code' => 'PRD-ALPHA']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'ALPHA-10', 'variant_name' => '10ml']);
        ProductVariant::factory()->count(21)->create();
        app(ProductPricingService::class)->addInitialPrice($variant, 'retail', '125000.50');
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/prices/retail?search=ALPHA-10&price_status=priced&product_id='.$product->id)
            ->assertOk()->assertSee('Serum Alpha')->assertSee('ALPHA-10')->assertSee('125.000,5 ₫');
        $this->get('/admin/prices/retail?page=2')->assertOk()
            ->assertViewHas('prices', fn ($page): bool => $page->total() === 22 && $page->currentPage() === 2);
        $this->get('/admin/prices/retail?search=ALPHA-10&price_status=unpriced')->assertOk()
            ->assertDontSee('Serum Alpha');
    }

    public function test_retail_edit_keeps_price_history_and_validation(): void
    {
        $this->withoutVite();
        $variant = ProductVariant::factory()->create(['sku' => 'RETAIL-BLADE']);
        $legacy = PriceList::factory()->create(['code' => 'RETAIL-OLD']);
        $old = $legacy->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        $this->actingAs(User::factory()->admin()->create());

        $this->from('/admin/prices/retail?search=RETAIL-BLADE')->patch('/admin/prices/retail/'.$variant->id, ['unit_price' => '125.50'])
            ->assertRedirect('/admin/prices/retail?search=RETAIL-BLADE')->assertSessionHas('success');
        $this->assertSame('inactive', $old->refresh()->status);
        $this->assertSame('125.50', app(RetailPricingService::class)->resolve($variant)['unit_price']);
        $this->from('/admin/prices/retail')->patch('/admin/prices/retail/'.$variant->id, ['unit_price' => '1.234'])
            ->assertSessionHasErrors('unit_price');
        $this->assertSame('125.50', app(RetailPricingService::class)->resolve($variant)['unit_price']);
    }

    public function test_price_book_conflict_returns_readable_blade_error_without_partial_price(): void
    {
        $variant = ProductVariant::factory()->create();
        PriceList::factory()->create(['code' => 'RETAIL-CATALOG-VND', 'status' => 'inactive']);
        $this->actingAs(User::factory()->admin()->create())
            ->from('/admin/prices/retail')
            ->patch(route('admin.web.prices.retail.update', $variant), ['unit_price' => '125.00'])
            ->assertRedirect('/admin/prices/retail')
            ->assertSessionHasErrors(['pricing' => 'Bảng giá hiện tại không hợp lệ. Vui lòng kiểm tra cấu hình bảng giá.']);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_dealer_tiers_missing_price_update_moq_and_delete_follow_existing_service(): void
    {
        $this->withoutVite();
        $variant = ProductVariant::factory()->create(['sku' => 'DEALER-BLADE', 'sellable_dealer' => true]);
        $silver = DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Silver', 'sort_order' => 1]);
        $gold = DealerTier::factory()->create(['code' => 'GOLD', 'name' => 'Gold', 'sort_order' => 2]);
        app(ProductPricingService::class)->addInitialPrice($variant, 'retail', '200.00');
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/prices/dealer?search=DEALER-BLADE')->assertOk()
            ->assertSee('Silver')->assertSee('Gold')->assertSee('Chưa có giá')->assertSee('200 ₫');
        $this->put('/admin/prices/dealer/'.$variant->id.'/'.$silver->id, ['unit_price' => '150.25', 'minimum_quantity' => 5])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('150.25', app(DealerPricingService::class)->resolve($variant, $silver, '5')['unit_price']);
        $this->get('/admin/prices/dealer?search=DEALER-BLADE&tier_id='.$gold->id)
            ->assertOk()->assertViewHas('prices', fn ($page): bool => $page->getCollection()->every(fn (array $row): bool => $row['tier_id'] === $gold->id))
            ->assertSee('Chưa có giá');
        $this->put('/admin/prices/dealer/'.$variant->id.'/'.$gold->id, ['unit_price' => '0', 'minimum_quantity' => 0])
            ->assertSessionHasErrors(['unit_price', 'minimum_quantity']);
        $this->delete('/admin/prices/dealer/'.$variant->id.'/'.$silver->id)->assertRedirect();
        $this->assertDatabaseHas('price_list_items', ['product_variant_id' => $variant->id, 'unit_price' => '150.25', 'status' => 'inactive']);
    }

    public function test_catalog_pricing_queries_do_not_grow_per_sku_and_tier(): void
    {
        $this->withoutVite();
        $tiers = DealerTier::factory()->count(3)->create();
        $variants = ProductVariant::factory()->count(6)->create();
        foreach ($variants as $variant) {
            app(ProductPricingService::class)->addInitialPrice($variant, 'retail', '100.00');
            foreach ($tiers as $tier) {
                app(ProductPricingService::class)->addInitialPrice($variant, 'dealer', '90.00', 1, $tier->id);
            }
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs(User::factory()->admin()->create())->get('/admin/prices/dealer')->assertOk();

        $priceQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `price_list_items`'));
        $this->assertLessThanOrEqual(3, count($priceQueries));
    }

    public function test_disabled_and_inactive_sku_remains_visible_without_a_price(): void
    {
        $this->withoutVite();
        $variant = ProductVariant::factory()->create([
            'sku' => 'DISABLED-PRICE-SKU',
            'status' => 'inactive',
            'sellable_retail' => false,
            'sellable_dealer' => false,
        ]);
        $tier = DealerTier::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/prices/retail?search=DISABLED-PRICE-SKU&status=inactive&price_status=unpriced')
            ->assertOk()->assertSee('DISABLED-PRICE-SKU')
            ->assertViewHas('prices', fn ($page): bool => $page->total() === 1
                && $page->getCollection()->first()['unit_price'] === null
                && ! $page->getCollection()->first()['sellable']);
        $this->get('/admin/prices/dealer?search=DISABLED-PRICE-SKU&status=inactive&price_status=unpriced&tier_id='.$tier->id)
            ->assertOk()->assertSee('DISABLED-PRICE-SKU')
            ->assertViewHas('prices', fn ($page): bool => $page->total() === 1
                && $page->getCollection()->first()['unit_price'] === null
                && ! $page->getCollection()->first()['sellable']);
    }
}
