<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductPricingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_cannot_open_product_list_or_detail(): void
    {
        $product = Product::factory()->create();

        $this->get('/admin/products')->assertRedirect('/login');
        $this->get('/admin/products/'.$product->id)->assertRedirect('/login');
    }

    public function test_customer_cannot_open_product_list_or_detail(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->customer()->create());

        $this->get('/admin/products')->assertForbidden();
        $this->get('/admin/products/'.$product->id)->assertForbidden();
    }

    public function test_admin_list_shows_real_products_primary_image_and_sku_count_without_loading_variants(): void
    {
        $this->withoutVite();
        config()->set('app.frontend_url', 'https://frontend.example.test');
        $product = Product::factory()->create(['name' => 'Ceramide Serum', 'product_code' => 'PRD-CERAMIDE']);
        ProductVariant::factory()->count(2)->for($product)->create();
        ProductImage::factory()->for($product)->create(['path' => 'products/secondary.jpg', 'is_primary' => false]);
        $primary = ProductImage::factory()->for($product)->create(['path' => 'products/primary.jpg', 'is_primary' => true]);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')
            ->assertOk()
            ->assertViewIs('admin.products.index')
            ->assertViewHas('products', fn ($page): bool => $page->total() === 1
                && $page->first()->variants_count === 2
                && ! $page->first()->relationLoaded('variants'))
            ->assertViewHas('primaryImages', fn ($images): bool => $images[$product->id]->id === $primary->id)
            ->assertSee('Ceramide Serum')
            ->assertSee('2 SKU')
            ->assertSee('products/primary.jpg')
            ->assertSee(route('admin.web.products.create'), false)
            ->assertSee(route('admin.web.products.edit', $product), false);
    }

    public function test_search_matches_product_code_name_sku_and_variant_name_and_reveals_draft(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['name' => 'Vitamin C', 'product_code' => 'PRD-VITAMIN', 'product_category_id' => $category->id]);
        ProductVariant::factory()->for($product)->create(['sku' => 'VIT-C-30', 'variant_name' => 'Chai 30ml']);
        $admin = User::factory()->admin()->create();
        $draft = Product::factory()->create(['name' => 'Unfinished Product', 'status' => 'draft', 'product_category_id' => $category->id,
            'wizard_key' => (string) Str::uuid(), 'wizard_owner_user_id' => $admin->id]);
        $this->actingAs($admin);

        $this->get('/admin/products')->assertOk()->assertDontSee('Unfinished Product');
        foreach (['Vitamin C', 'PRD-VITAMIN', 'VIT-C-30', 'Chai 30ml'] as $search) {
            $this->get('/admin/products?search='.urlencode($search))
                ->assertOk()->assertSee('Vitamin C')->assertDontSee('Unfinished Product');
        }
        $this->get('/admin/products?search=Unfinished')
            ->assertOk()->assertSee('Unfinished Product')->assertSee('Mở bản nháp')
            ->assertSee(route('admin.web.products.drafts.edit', $draft));
        $this->assertDatabaseHas('products', ['id' => $draft->id, 'status' => 'draft']);
    }

    public function test_category_brand_status_and_gift_filters_use_existing_product_semantics(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create();
        $otherCategory = ProductCategory::factory()->create();
        $brand = Brand::factory()->create();
        Product::factory()->create(['name' => 'Matching Serum', 'product_category_id' => $category->id,
            'brand_id' => $brand->id, 'status' => 'active', 'gift_only' => false]);
        Product::factory()->create(['name' => 'Gift Serum', 'product_category_id' => $category->id,
            'brand_id' => $brand->id, 'status' => 'active', 'can_be_gift' => true, 'gift_only' => true]);
        Product::factory()->create(['name' => 'Other Category Serum', 'product_category_id' => $otherCategory->id,
            'brand_id' => $brand->id, 'status' => 'active']);
        Product::factory()->create(['name' => 'Inactive Serum', 'product_category_id' => $category->id,
            'brand_id' => $brand->id, 'status' => 'inactive']);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products?category='.$category->id.'&brand='.$brand->id.'&status=active&gift_filter=normal')
            ->assertOk()->assertSee('Matching Serum')->assertDontSee('Gift Serum')
            ->assertDontSee('Other Category Serum')->assertDontSee('Inactive Serum');

        $this->get('/admin/products?gift_filter=gift_only')
            ->assertOk()->assertSee('Gift Serum')->assertDontSee('Matching Serum');
    }

    public function test_pagination_keeps_search_and_filter_query(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create();
        Product::factory()->count(21)->create(['name' => 'Serum Batch', 'product_category_id' => $category->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/products?search=Serum&status=active&category='.$category->id.'&page=2')
            ->assertOk()
            ->assertViewHas('products', fn ($page): bool => $page->currentPage() === 2
                && $page->total() === 21 && $page->count() === 1)
            ->assertSee('search=Serum')
            ->assertSee('status=active')
            ->assertSee('category='.$category->id);
    }

    public function test_list_loads_images_in_one_query_for_multiple_products(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create();
        $products = Product::factory()->count(6)->create(['product_category_id' => $category->id]);
        foreach ($products as $product) {
            ProductImage::factory()->for($product)->create();
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')->assertOk();

        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `product_images`')));
    }

    public function test_temporary_react_card_hiding_is_preserved_only_for_the_exact_product_pair(): void
    {
        $this->withoutVite();
        Product::factory()->create(['product_code' => 'PRD000017', 'name' => 'Cica Repair Cream']);
        Product::factory()->create(['product_code' => 'PRD-SAFE', 'name' => 'Cica Repair Cream']);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')
            ->assertOk()
            ->assertViewHas('products', fn ($page): bool => $page->total() === 2)
            ->assertDontSee('PRD000017')
            ->assertSee('PRD-SAFE');
    }

    public function test_invalid_filter_returns_validation_error(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from('/admin/products')
            ->get('/admin/products?gift_filter=unknown')
            ->assertRedirect('/admin/products')
            ->assertSessionHasErrors(['gift_filter']);
    }

    public function test_detail_shows_sku_images_and_price_from_existing_service(): void
    {
        $this->withoutVite();
        config()->set('app.frontend_url', 'https://frontend.example.test');
        $product = Product::factory()->create(['name' => 'Vitamin Serum', 'default_low_stock_threshold' => '5.000']);
        $variant = ProductVariant::factory()->for($product)->create(['sku' => 'SERUM-30ML', 'variant_name' => 'Chai 30ml']);
        ProductImage::factory()->for($product)->create(['path' => 'products/vitamin.jpg', 'product_variant_id' => $variant->id]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->for($variant, 'variant')->for($priceList, 'priceList')->create(['unit_price' => '125000.00']);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products/'.$product->id)
            ->assertOk()
            ->assertViewIs('admin.products.show')
            ->assertSee('Vitamin Serum')
            ->assertSee('SERUM-30ML')
            ->assertSee('Chai 30ml')
            ->assertSee('125.000 ₫')
            ->assertSee('products/vitamin.jpg')
            ->assertSee(route('admin.web.prices.retail.index', ['product_id' => $product->id]), false)
            ->assertSee(route('admin.web.prices.dealer.index', ['product_id' => $product->id]), false)
            ->assertSee(route('admin.web.inventory.index', ['product_id' => $product->id]), false);
    }

    public function test_detail_price_queries_remain_bounded_for_many_skus_and_tiers(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        $variants = ProductVariant::factory()->count(8)->for($product)->create();
        $tiers = DealerTier::factory()->count(3)->create();
        $pricing = app(ProductPricingService::class);
        $pricing->addInitialPrice($variants[0], 'retail', '120000');
        $pricing->addInitialPrice($variants[1], 'dealer', '90000', 10, $tiers[0]->id);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.web.products.show', $product))
            ->assertOk()->assertSee('8')->assertSee('120.000 ₫');

        $this->assertCount(2, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `price_list_items`')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `product_variants`')));
    }

    public function test_missing_product_returns_404(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/products/999999')->assertNotFound();
    }
}
