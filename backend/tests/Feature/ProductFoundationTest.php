<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RetailPricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_public_product_filters_return_only_active_categories_and_brands(): void
    {
        $category = ProductCategory::factory()->create(['code' => 'SKIN', 'name' => 'Skin']);
        $brand = Brand::factory()->create(['code' => 'JUNIE', 'name' => 'Junie']);
        ProductCategory::factory()->create(['status' => 'inactive']);
        Brand::factory()->create(['status' => 'inactive']);

        $this->getJson('/api/product-filters')
            ->assertOk()
            ->assertJsonPath('categories.0.id', $category->id)
            ->assertJsonPath('categories.0.code', 'SKIN')
            ->assertJsonPath('brands.0.id', $brand->id)
            ->assertJsonPath('brands.0.code', 'JUNIE')
            ->assertJsonCount(1, 'categories')
            ->assertJsonCount(1, 'brands');
    }

    public function test_master_crud_and_category_cycle_validation(): void
    {
        $this->admin();
        foreach (['categories' => ['code' => 'skin', 'name' => 'Skin'], 'brands' => ['code' => 'acme', 'name' => 'Acme'], 'units' => ['code' => 'pcs', 'name' => 'Piece', 'symbol' => 'pc', 'decimal_precision' => 0]] as $kind => $body) {
            $response = $this->postJson("/api/admin/product-master/{$kind}", $body)->assertCreated();
            $this->assertSame(strtoupper($body['code']), $response->json('data.code'));
            $this->patchJson("/api/admin/product-master/{$kind}/".$response->json('data.id'), ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
            $this->postJson("/api/admin/product-master/{$kind}", $body)->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $root = ProductCategory::factory()->create();
        $child = ProductCategory::factory()->create(['parent_id' => $root->id]);
        $this->patchJson("/api/admin/product-master/categories/{$root->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_product_code_default_sku_and_normalization(): void
    {
        $this->admin();
        $category = ProductCategory::factory()->create();
        $unit = Unit::factory()->create();
        $response = $this->postJson('/api/admin/products', [
            'name' => 'Serum', 'slug' => 'serum', 'product_category_id' => $category->id,
            'default_unit_id' => $unit->id,
        ])->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame('PRD'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), $response->json('data.product_code'));
        $this->assertSame($response->json('data.product_code').'-DEFAULT', $response->json('data.variants.0.sku'));
        $this->patchJson("/api/admin/products/{$id}", ['product_code' => 'MUTATED', 'status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->patchJson("/api/admin/products/{$id}", ['name' => 'Serum updated', 'slug' => 'serum'])->assertOk()->assertJsonPath('data.slug', 'serum');
        $this->assertDatabaseHas('products', ['id' => $id, 'product_code' => $response->json('data.product_code')]);
        $variantResponse = $this->postJson("/api/admin/products/{$id}/variants", ['sku' => '  serum-30ml  ', 'variant_name' => '30 ml', 'unit_id' => $unit->id, 'sellable_retail' => true])
            ->assertCreated()->assertJsonPath('data.sku', 'SERUM-30ML');
        $this->patchJson("/api/admin/products/{$id}/variants/".$variantResponse->json('data.id'), ['sku' => ' serum-30ml ', 'variant_name' => '30 ml updated'])
            ->assertOk()->assertJsonPath('data.sku', 'SERUM-30ML');
        $this->postJson("/api/admin/products/{$id}/variants", ['sku' => 'SeRuM-30ML', 'variant_name' => 'Duplicate', 'unit_id' => $unit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_retail_price_resolution_dates_and_ambiguity(): void
    {
        $this->admin();
        $variant = ProductVariant::factory()->create();
        $list = PriceList::factory()->create();
        $this->postJson("/api/admin/retail-price-lists/{$list->id}/items", [
            'product_variant_id' => $variant->id, 'unit_price' => '120000.50',
        ])->assertCreated();
        $price = app(RetailPricingService::class)->resolve($variant);
        $this->assertSame('120000.50', $price['unit_price']);
        $this->assertSame('retail', $price['pricing_context']);
        $this->assertSame('120000.50', app(RetailPricingService::class)->resolveSku('  '.strtolower($variant->sku).'  ')['unit_price']);
        $this->postJson("/api/admin/retail-price-lists/{$list->id}/items", [
            'product_variant_id' => $variant->id, 'unit_price' => '130000.00',
        ])->assertConflict()->assertJsonPath('code', 'PRICE_AMBIGUOUS');
        $itemId = $price['price_list_item_id'];
        $this->patchJson("/api/admin/retail-price-lists/{$list->id}/items/{$itemId}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->postJson("/api/admin/retail-price-lists/{$list->id}/items", [
            'product_variant_id' => $variant->id, 'unit_price' => '130000.00',
        ])->assertCreated();
        $this->assertSame('130000.00', app(RetailPricingService::class)->resolve($variant)['unit_price']);
        $future = ProductVariant::factory()->create();
        $futureItem = $this->postJson("/api/admin/retail-price-lists/{$list->id}/items", [
            'product_variant_id' => $future->id, 'unit_price' => '130000.00',
            'effective_from' => now()->addDay()->toDateTimeString(),
        ])->assertCreated();
        $this->patchJson("/api/admin/retail-price-lists/{$list->id}/items/".$futureItem->json('data.id'), [
            'effective_to' => now()->subDay()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_to');
        try {
            app(RetailPricingService::class)->resolve($future);
            $this->fail('Missing current price must fail.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PRICE_NOT_FOUND', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_public_catalog_hides_inactive_and_non_retail_skus(): void
    {
        $product = Product::factory()->create();
        $visible = ProductVariant::factory()->for($product)->create([
            'sku' => 'VISIBLE',
            'sellable_retail' => true,
            'specifications' => ['size' => '30 ml', 'dealer_price' => 'private'],
        ]);
        ProductVariant::factory()->for($product)->create(['sku' => 'DEALER-ONLY', 'sellable_retail' => false, 'sellable_dealer' => true]);
        $list = PriceList::factory()->create();
        $list->items()->create(['product_variant_id' => $visible->id, 'unit_price' => '90000', 'minimum_quantity' => 1]);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.variants.0.sku', 'VISIBLE')
            ->assertJsonPath('data.0.retail_price.pricing_context', 'retail')
            ->assertJsonPath('data.0.variants.0.specifications.size', '30 ml')
            ->assertDontSee('DEALER-ONLY')
            ->assertDontSee('dealer_price')
            ->assertDontSee('private');
        $this->getJson("/api/products/{$product->slug}")->assertOk()->assertJsonPath('data.variants.0.sku', 'VISIBLE');
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("/api/products/{$product->slug}")->assertOk()->assertJsonPath('data.variants.0.sku', 'VISIBLE');
        $product->update(['status' => 'inactive']);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
    }

    public function test_public_detail_and_catalog_hide_a_product_without_priced_retail_skus(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['sellable_retail' => true]);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
        try {
            app(RetailPricingService::class)->resolve($variant);
            $this->fail('An unpriced SKU must not resolve to a purchasable price.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PRICE_NOT_FOUND', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_public_detail_only_exposes_currently_priced_retail_skus(): void
    {
        $product = Product::factory()->create();
        $list = PriceList::factory()->create();
        foreach (['SKU-A', 'SKU-B', 'SKU-C'] as $sku) {
            $variant = ProductVariant::factory()->for($product)->create(['sku' => $sku, 'sellable_retail' => true]);
            if ($sku !== 'SKU-C') {
                $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '90000', 'minimum_quantity' => 1]);
            }
        }

        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.variants')
            ->assertDontSee('SKU-C');
        $this->getJson("/api/products/{$product->slug}")->assertOk()
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.sku', 'SKU-A')
            ->assertJsonPath('data.variants.1.sku', 'SKU-B')
            ->assertJsonPath('data.variants.0.retail_price.pricing_context', 'retail')
            ->assertDontSee('SKU-C');

        $this->admin();
        $this->getJson("/api/admin/products/{$product->id}")->assertOk()
            ->assertJsonCount(3, 'data.variants')
            ->assertSee('SKU-C');
    }

    public function test_non_admin_roles_cannot_mutate_product_master(): void
    {
        $this->postJson('/api/admin/products', [])->assertUnauthorized();
        $this->postJson('/api/admin/retail-price-lists', [])->assertUnauthorized();
        foreach (['customer', 'receptionist', 'doctor'] as $role) {
            Sanctum::actingAs(User::factory()->{$role}()->create());
            $this->postJson('/api/admin/product-master/brands', ['code' => 'NOPE', 'name' => 'Nope'])->assertForbidden();
            $this->postJson('/api/admin/products', [])->assertForbidden();
            $this->postJson('/api/admin/retail-price-lists', [])->assertForbidden();
        }
    }

    public function test_retail_priority_and_list_activation_conflicts(): void
    {
        $this->admin();
        $variant = ProductVariant::factory()->create();
        $base = PriceList::factory()->create(['priority' => 0]);
        $base->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => 1]);
        $higher = PriceList::factory()->create(['priority' => 10]);
        $this->postJson("/api/admin/retail-price-lists/{$higher->id}/items", [
            'product_variant_id' => $variant->id, 'unit_price' => '120.00',
        ])->assertCreated();
        $this->assertSame('120.00', app(RetailPricingService::class)->resolve($variant)['unit_price']);
        $inactive = PriceList::factory()->create(['priority' => 10, 'status' => 'inactive']);
        $this->postJson("/api/admin/retail-price-lists/{$inactive->id}/items", [
            'product_variant_id' => $variant->id, 'unit_price' => '130.00',
        ])->assertCreated();
        $this->patchJson("/api/admin/retail-price-lists/{$inactive->id}", ['status' => 'active'])
            ->assertConflict()->assertJsonPath('code', 'PRICE_AMBIGUOUS');
        $this->assertDatabaseHas('price_lists', ['id' => $inactive->id, 'status' => 'inactive']);
    }

    public function test_retail_never_falls_back_to_dealer_context_and_honors_list_dates(): void
    {
        $variant = ProductVariant::factory()->create();
        $dealerOnly = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier']);
        $dealerOnly->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '70', 'minimum_quantity' => 1]);
        try {
            app(RetailPricingService::class)->resolve($variant);
            $this->fail('Dealer price must not become Retail price.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PRICE_NOT_FOUND', $exception->getResponse()->getData(true)['code']);
        }
        $retail = PriceList::factory()->create(['effective_from' => now()->addDay(), 'effective_to' => now()->addDays(3)]);
        $retail->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100', 'minimum_quantity' => 1]);
        try {
            app(RetailPricingService::class)->resolve($variant);
            $this->fail('Future Retail price must not apply now.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PRICE_NOT_FOUND', $exception->getResponse()->getData(true)['code']);
        }
        $this->assertSame('100.00', app(RetailPricingService::class)->resolve($variant, at: now()->addDays(2))['unit_price']);
    }

    public function test_admin_product_search_finds_a_variant_name(): void
    {
        $product = Product::factory()->create(['name' => 'Hydrating serum', 'status' => 'active']);
        ProductVariant::factory()->for($product)->create([
            'sku' => 'UNIQUE-SERUM-01', 'variant_name' => 'Travel size',
        ]);
        Product::factory()->create(['name' => 'Other product', 'status' => 'active']);
        $this->admin();

        $this->getJson('/api/admin/products?search=Travel%20size&status=active')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $product->id);
    }

    public function test_catalog_search_filter_pagination_and_inactive_sku(): void
    {
        $category = ProductCategory::factory()->create();
        $brand = Brand::factory()->create();
        $list = PriceList::factory()->create();
        foreach (['Serum A', 'Serum B'] as $name) {
            $product = Product::factory()->create(['name' => $name, 'product_category_id' => $category->id, 'brand_id' => $brand->id]);
            $variant = ProductVariant::factory()->for($product)->create();
            $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100', 'minimum_quantity' => 1]);
        }
        $this->getJson("/api/products?category={$category->id}&brand={$brand->id}&search=Serum&per_page=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/products?search=Serum%20A')->assertOk()->assertJsonCount(1, 'data');
        ProductVariant::query()->whereHas('product', fn ($query) => $query->where('name', 'Serum A'))->update(['status' => 'inactive']);
        $this->getJson('/api/products?search=Serum%20A')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_catalog_eager_loads_prices_images_and_units(): void
    {
        $list = PriceList::factory()->create();
        foreach (range(1, 5) as $number) {
            $product = Product::factory()->create(['name' => "Catalog {$number}"]);
            $variant = ProductVariant::factory()->for($product)->create();
            ProductImage::factory()->for($product)->create();
            $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '100', 'minimum_quantity' => 1]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(5, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(13, $queryCount);
    }

    public function test_image_upload_validates_file_and_variant_ownership(): void
    {
        $this->admin();
        Storage::fake('public');
        $product = Product::factory()->create();
        $foreignVariant = ProductVariant::factory()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
        $this->postJson("/api/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->create('notes.pdf', 1, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson("/api/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->createWithContent('serum.png', $png),
            'product_variant_id' => $foreignVariant->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');
        $image = $this->postJson("/api/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->createWithContent('serum.png', $png),
        ])->assertCreated()->assertJsonPath('data.is_primary', true);
        $path = $image->json('data.path');
        Storage::disk('public')->assertExists($path);
        $variant = ProductVariant::factory()->for($product)->create();
        $list = PriceList::factory()->create();
        $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '90000', 'minimum_quantity' => 1]);
        $this->getJson("/api/products/{$product->slug}")->assertOk()
            ->assertJsonPath('data.images.0.url', url(Storage::disk('public')->url($path)))
            ->assertJsonMissingPath('data.images.0.path');
        $this->deleteJson("/api/admin/products/{$product->id}/images/".$image->json('data.id'))->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }
}
