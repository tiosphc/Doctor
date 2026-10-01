<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\DealerPricingService;
use App\Services\ProductPricingService;
use App\Services\RetailPricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_searches_variant_name_and_filters_effective_sellable_status(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        ProductVariant::factory()->create(['sku' => 'VARIANT-ONE', 'variant_name' => 'Special size', 'sellable_retail' => true]);
        ProductVariant::factory()->create(['sku' => 'VARIANT-TWO', 'variant_name' => 'Other size', 'sellable_retail' => false]);

        $this->getJson('/api/admin/retail-prices?search=Special%20size&status=active')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sku', 'VARIANT-ONE');
        $this->getJson('/api/admin/retail-prices?status=inactive')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sku', 'VARIANT-TWO');
    }

    public function test_retail_catalog_filters_price_before_pagination_and_includes_product_image(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $priced = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'FILTER-PRICED']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'FILTER-MISSING']);
        ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/secondary.jpg', 'is_primary' => false]);
        ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/primary.jpg', 'is_primary' => true]);
        app(ProductPricingService::class)->addInitialPrice($priced, 'retail', '125000');

        $this->getJson('/api/admin/retail-prices?price_status=priced&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'FILTER-PRICED')
            ->assertJsonPath('data.0.unit_price', '125000.00')
            ->assertJsonPath('data.0.product_image_url', url('/storage/products/primary.jpg'));
        $this->getJson('/api/admin/retail-prices?price_status=unpriced&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'FILTER-MISSING');
    }

    public function test_dealer_catalog_filters_complete_and_missing_tier_prices_with_retail_reference(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $silver = DealerTier::factory()->create(['name' => 'Silver', 'sort_order' => 1]);
        $gold = DealerTier::factory()->create(['name' => 'Gold', 'sort_order' => 2]);
        $partial = ProductVariant::factory()->create(['sku' => 'FILTER-PARTIAL']);
        $complete = ProductVariant::factory()->create(['sku' => 'FILTER-COMPLETE']);
        $pricing = app(ProductPricingService::class);
        $pricing->addInitialPrice($partial, 'retail', '200000');
        $pricing->addInitialPrice($partial, 'dealer', '150000', 5, $silver->id);
        $pricing->addInitialPrice($complete, 'dealer', '140000', 5, $silver->id);
        $pricing->addInitialPrice($complete, 'dealer', '130000', 10, $gold->id);

        $this->getJson('/api/admin/dealer-prices?price_status=unpriced&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sku', 'FILTER-PARTIAL')
            ->assertJsonPath('data.0.retail_reference_price', '200000.00')
            ->assertJsonPath('data.0.minimum_quantity', 5)
            ->assertJsonPath('data.1.unit_price', null);
        $this->getJson('/api/admin/dealer-prices?price_status=priced&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'FILTER-COMPLETE');
        $this->getJson("/api/admin/dealer-prices?price_status=priced&tier_id={$silver->id}")
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');
        $this->getJson("/api/admin/dealer-prices?price_status=unpriced&tier_id={$gold->id}")
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'FILTER-PARTIAL');
    }

    public function test_admin_edits_variant_retail_and_tier_prices_without_quantity_discounts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $first = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'PRICE-A']);
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'PRICE-B']);
        $tier = DealerTier::factory()->create();
        $old = PriceList::factory()->create();
        $oldItem = $old->items()->create(['product_variant_id' => $first->id, 'unit_price' => '500.00', 'minimum_quantity' => 1]);
        $old->items()->create(['product_variant_id' => $first->id, 'unit_price' => '400.00', 'minimum_quantity' => 10]);
        $this->assertSame('500.00', app(RetailPricingService::class)->resolve($first, quantity: '50')['unit_price']);

        $this->patchJson("/api/admin/products/{$product->id}/pricing", [
            'sellable_retail' => true, 'sellable_dealer' => true, 'retail_price' => '120.00',
            'variant_retail_prices' => [['sku' => 'PRICE-B', 'unit_price' => '130.00']],
            'dealer_rules' => [
                ['tier_id' => $tier->id, 'sku' => 'PRICE-A', 'min_quantity' => 10, 'unit_price' => '98.00'],
                ['tier_id' => $tier->id, 'sku' => 'PRICE-B', 'min_quantity' => 5, 'unit_price' => '105.00'],
            ],
        ])->assertOk()->assertJsonPath('data.dealer_rules.0.min_quantity', '10');
        $this->getJson('/api/admin/retail-prices?search=PRICE-A')->assertOk()
            ->assertJsonPath('data.0.unit_symbol', $first->unit->symbol);

        $this->assertDatabaseHas('price_list_items', ['id' => $oldItem->id, 'status' => 'inactive']);
        $this->assertSame('120.00', app(RetailPricingService::class)->resolve($first->refresh(), quantity: '50')['unit_price']);
        $this->assertSame('130.00', app(RetailPricingService::class)->resolve($second->refresh(), quantity: '50')['unit_price']);
        $dealer = app(DealerPricingService::class);
        $this->assertSame('98.00', $dealer->resolve($first->refresh(), $tier, '10')['unit_price']);
        $this->assertSame('98.00', $dealer->resolve($first, $tier, '50')['unit_price']);
        $this->assertSame('4900.00', $dealer->resolve($first, $tier, '50')['line_total']);
        try {
            $dealer->resolve($first, $tier, '5');
            $this->fail('Expected MOQ conflict.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('DEALER_MOQ_NOT_MET', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_duplicate_tier_sku_is_rejected_without_modifying_existing_prices(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $variant = ProductVariant::factory()->create(['sku' => 'SAME-SKU']);
        $tier = DealerTier::factory()->create();

        $this->patchJson("/api/admin/products/{$variant->product_id}/pricing", [
            'sellable_retail' => false, 'sellable_dealer' => true,
            'dealer_rules' => [
                ['tier_id' => $tier->id, 'sku' => 'SAME-SKU', 'min_quantity' => 10, 'unit_price' => 100],
                ['tier_id' => $tier->id, 'sku' => 'SAME-SKU', 'min_quantity' => 20, 'unit_price' => 90],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('dealer_rules.1.sku');

        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_duplicate_retail_variant_override_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $variant = ProductVariant::factory()->create(['sku' => 'RETAIL-SKU']);

        $this->patchJson("/api/admin/products/{$variant->product_id}/pricing", [
            'sellable_retail' => true, 'sellable_dealer' => false, 'retail_price' => 100,
            'variant_retail_prices' => [
                ['sku' => 'RETAIL-SKU', 'unit_price' => 120],
                ['sku' => 'RETAIL-SKU', 'unit_price' => 130],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('variant_retail_prices.1.sku');

        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_dealer_price_never_falls_back_to_retail(): void
    {
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true]);
        $tier = DealerTier::factory()->create();
        $retail = PriceList::factory()->create();
        $retail->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => 1]);

        try {
            app(DealerPricingService::class)->resolve($variant, $tier, '10');
            $this->fail('Expected missing Dealer price.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('DEALER_PRICE_NOT_FOUND', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_product_pricing_write_requires_admin(): void
    {
        $product = Product::factory()->create();
        $body = ['sellable_retail' => true, 'sellable_dealer' => false, 'retail_price' => '100.00'];

        $this->patchJson("/api/admin/products/{$product->id}/pricing", $body)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->patchJson("/api/admin/products/{$product->id}/pricing", $body)->assertForbidden();

        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_central_retail_price_and_product_editor_share_the_same_sku_price(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $variant = ProductVariant::factory()->create(['sku' => 'CENTRAL-RETAIL']);
        $legacy = PriceList::factory()->create(['code' => 'EDITR-LEGACY']);
        $oldItem = $legacy->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => 1]);

        $this->getJson('/api/admin/retail-prices?search=CENTRAL-RETAIL')
            ->assertOk()->assertJsonPath('data.0.unit_price', '100.00');
        $this->patchJson("/api/admin/retail-prices/{$variant->id}", ['unit_price' => '125.00'])->assertOk();
        $this->getJson("/api/admin/products/{$variant->product_id}/pricing")
            ->assertOk()->assertJsonPath('data.retail_price', '125.00');
        $this->assertSame('125.00', app(RetailPricingService::class)->resolve($variant)['unit_price']);
        $this->assertDatabaseHas('price_list_items', ['id' => $oldItem->id, 'status' => 'inactive', 'unit_price' => '100.00']);

        $this->patchJson("/api/admin/products/{$variant->product_id}/pricing", [
            'sellable_retail' => true, 'sellable_dealer' => false,
            'retail_price' => '150.00', 'variant_retail_prices' => [],
            'dealer_rules' => [],
        ])->assertOk();
        $this->getJson('/api/admin/retail-prices?search=CENTRAL-RETAIL')
            ->assertOk()->assertJsonPath('data.0.unit_price', '150.00');
        $this->assertDatabaseCount('price_lists', 2);
        $this->assertDatabaseHas('price_lists', ['code' => 'RETAIL-CATALOG-VND']);
    }

    public function test_central_dealer_prices_are_tier_scoped_and_hidden_from_retail_books(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $variant = ProductVariant::factory()->create(['sku' => 'CENTRAL-DEALER', 'sellable_dealer' => true]);
        $silver = DealerTier::factory()->create(['name' => 'Silver']);
        $gold = DealerTier::factory()->create(['name' => 'Gold']);
        $this->putJson("/api/admin/dealer-prices/{$variant->id}/{$silver->id}", ['unit_price' => 90, 'minimum_quantity' => 5])->assertOk();
        $this->putJson("/api/admin/dealer-prices/{$variant->id}/{$gold->id}", ['unit_price' => 80, 'minimum_quantity' => 1])->assertOk();
        $this->assertSame('90.00', app(DealerPricingService::class)->resolve($variant, $silver, '5')['unit_price']);
        $this->assertSame('80.00', app(DealerPricingService::class)->resolve($variant, $gold, '5')['unit_price']);
        $this->getJson("/api/admin/dealer-prices?search=CENTRAL-DEALER&tier_id={$silver->id}")
            ->assertOk()->assertJsonPath('data.0.unit_price', '90.00')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/retail-price-lists')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/admin/dealer-prices/{$variant->id}/{$silver->id}")->assertOk();
        $this->getJson("/api/admin/products/{$variant->product_id}/pricing")
            ->assertOk()->assertJsonCount(1, 'data.dealer_rules');
        $this->assertSame('80.00', app(DealerPricingService::class)->resolve($variant, $gold, '5')['unit_price']);
    }
}
