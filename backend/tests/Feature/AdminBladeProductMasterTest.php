<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminBladeProductMasterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_category_and_brand_pages_and_writes_require_admin(): void
    {
        $category = ProductCategory::factory()->create();
        $brand = Brand::factory()->create();

        foreach (['/admin/categories', '/admin/categories/create', '/admin/categories/'.$category->id.'/edit',
            '/admin/brands', '/admin/brands/create', '/admin/brands/'.$brand->id.'/edit'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->post('/admin/categories', ['code' => 'SKIN', 'name' => 'Skin'])->assertRedirect('/login');
        $this->patch('/admin/brands/'.$brand->id, ['status' => 'inactive'])->assertRedirect('/login');
        $this->actingAs(User::factory()->customer()->create());
        foreach (['/admin/categories', '/admin/categories/create', '/admin/categories/'.$category->id.'/edit',
            '/admin/brands', '/admin/brands/create', '/admin/brands/'.$brand->id.'/edit'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/admin/categories', ['code' => 'SKIN', 'name' => 'Skin'])->assertForbidden();
        $this->patch('/admin/brands/'.$brand->id, ['status' => 'inactive'])->assertForbidden();
        $this->assertSame('active', $brand->refresh()->status);
    }

    public function test_category_list_search_pagination_parent_and_product_count(): void
    {
        $this->withoutVite();
        $parent = ProductCategory::factory()->create(['code' => 'ROOT', 'name' => 'Root']);
        $category = ProductCategory::factory()->create(['code' => 'SERUM', 'name' => 'Serum', 'parent_id' => $parent->id]);
        Product::factory()->count(2)->create(['product_category_id' => $category->id]);
        ProductCategory::factory()->count(20)->create(['name' => 'Other']);
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/categories?search=SERUM')->assertOk()->assertViewIs('admin.categories.index')
            ->assertSee('Serum')->assertSee('Root')->assertSee('2</td>', false)
            ->assertDontSee('Other');
        $this->get('/admin/categories?page=2')->assertOk()
            ->assertViewHas('categories', fn ($page): bool => $page->total() === 22 && $page->currentPage() === 2)
            ->assertSee('page=1');
    }

    public function test_category_create_update_duplicate_code_and_cycle_follow_api_rules(): void
    {
        $this->withoutVite();
        $parent = ProductCategory::factory()->create(['name' => 'Parent']);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/categories/create')
            ->assertOk()->assertSee('Parent');
        $this->post('/admin/categories', [
            'code' => ' skin ', 'name' => 'Skin', 'parent_id' => $parent->id,
            'description' => 'Skin care', 'sort_order' => 3, 'status' => 'active',
        ])->assertRedirect(route('admin.web.categories.index'));
        $category = ProductCategory::query()->where('code', 'SKIN')->firstOrFail();
        $this->assertSame($parent->id, $category->parent_id);
        $this->assertSame(3, $category->sort_order);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/categories/'.$category->id.'/edit')
            ->assertOk()->assertSee('Skin')->assertSee('SKIN');

        $this->from('/admin/categories/create')->post('/admin/categories', ['code' => 'skin', 'name' => 'Copy'])
            ->assertRedirect('/admin/categories/create')->assertSessionHasErrors('code')
            ->assertSessionHasInput('name', 'Copy');
        $this->patch('/admin/categories/'.$parent->id, ['parent_id' => $category->id])
            ->assertSessionHasErrors('parent_id');
        $this->assertNull($parent->refresh()->parent_id);
        $this->patch('/admin/categories/'.$category->id, ['name' => 'Skin Updated'])
            ->assertRedirect(route('admin.web.categories.edit', $category));
        $this->assertSame('Skin Updated', $category->refresh()->name);
    }

    public function test_brand_list_create_edit_and_deactivate_preserve_product_reference(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/brands/create')->assertOk();
        $this->post('/admin/brands', ['code' => ' acme ', 'name' => 'Acme', 'description' => 'Supplier'])
            ->assertRedirect(route('admin.web.brands.index'));
        $brand = Brand::query()->where('code', 'ACME')->firstOrFail();
        $product = Product::factory()->create(['product_category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->get('/admin/brands?search=ACME')->assertOk()->assertViewIs('admin.brands.index')
            ->assertSee('Acme')->assertSee('1</td>', false);
        $this->get('/admin/brands/'.$brand->id.'/edit')->assertOk()->assertSee('Supplier');
        $this->patch('/admin/brands/'.$brand->id, ['name' => 'Acme New', 'status' => 'inactive'])
            ->assertRedirect(route('admin.web.brands.edit', $brand));
        $this->assertSame('Acme New', $brand->refresh()->name);
        $this->assertSame('inactive', $brand->status);
        $this->assertSame($brand->id, $product->refresh()->brand_id);
        $this->from('/admin/brands/create')->post('/admin/brands', ['code' => 'acme', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }

    public function test_category_and_brand_status_filters_and_reactivation(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create(['name' => 'Retired Category', 'status' => 'active']);
        $brand = Brand::factory()->create(['name' => 'Retired Brand', 'status' => 'active']);
        $product = Product::factory()->create(['product_category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->actingAs(User::factory()->admin()->create());

        $this->patch('/admin/categories/'.$category->id, ['status' => 'inactive'])
            ->assertRedirect(route('admin.web.categories.edit', $category));
        $this->patch('/admin/brands/'.$brand->id, ['status' => 'inactive'])
            ->assertRedirect(route('admin.web.brands.edit', $brand));
        $this->get('/admin/categories?status=inactive')->assertOk()->assertSee('Retired Category');
        $this->get('/admin/categories?status=active')->assertOk()->assertDontSee('Retired Category');
        $this->get('/admin/brands?status=inactive')->assertOk()->assertSee('Retired Brand');
        $this->get('/admin/brands?status=active')->assertOk()->assertDontSee('Retired Brand');
        $this->assertSame($category->id, $product->refresh()->product_category_id);
        $this->assertSame($brand->id, $product->brand_id);

        $this->patch('/admin/categories/'.$category->id, ['status' => 'active'])->assertRedirect();
        $this->patch('/admin/brands/'.$brand->id, ['status' => 'active'])->assertRedirect();
        $this->assertSame('active', $category->refresh()->status);
        $this->assertSame('active', $brand->refresh()->status);
    }

    public function test_new_active_category_and_brand_can_be_selected_on_product_create(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/categories', ['code' => 'NEWCAT', 'name' => 'New Category'])
            ->assertRedirect(route('admin.web.categories.index'));
        $this->post('/admin/brands', ['code' => 'NEWBRAND', 'name' => 'New Brand'])
            ->assertRedirect(route('admin.web.brands.index'));
        $category = ProductCategory::query()->where('code', 'NEWCAT')->firstOrFail();
        $brand = Brand::query()->where('code', 'NEWBRAND')->firstOrFail();
        $unit = Unit::factory()->create();

        $this->get('/admin/products/create')->assertOk()->assertSee('New Category')->assertSee('New Brand')
            ->assertSee(route('admin.web.categories.index'))->assertSee(route('admin.web.brands.index'));
        $this->post('/admin/products', [
            'name' => 'New Master Product', 'slug' => 'new-master-product', 'base_sku' => 'NEWMASTER',
            'product_category_id' => $category->id, 'brand_id' => $brand->id,
            'variants' => [['variant_name' => 'Default', 'unit_id' => $unit->id, 'sellable_retail' => '1', 'sellable_dealer' => '0']],
        ])->assertRedirect();
        $product = Product::query()->where('slug', 'new-master-product')->firstOrFail();
        $this->assertSame($category->id, $product->product_category_id);
        $this->assertSame($brand->id, $product->brand_id);
    }

    public function test_inactive_referenced_masters_remain_on_product_edit_but_not_create(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create(['name' => 'Retired Category', 'status' => 'inactive']);
        $brand = Brand::factory()->create(['name' => 'Retired Brand', 'status' => 'inactive']);
        ProductCategory::factory()->create(['name' => 'Active Category']);
        Brand::factory()->create(['name' => 'Active Brand']);
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['product_category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/products/create')->assertOk()->assertSee('Active Category')
            ->assertDontSee('Retired Category')->assertDontSee('Retired Brand');
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()
            ->assertSee('Retired Category (ngừng hoạt động, đang sử dụng)')
            ->assertSee('Retired Brand (ngừng hoạt động, đang sử dụng)');
        $this->patch('/admin/products/'.$product->id, [
            'name' => 'Updated Product', 'slug' => 'updated-product',
            'product_category_id' => $category->id, 'brand_id' => $brand->id,
        ])->assertRedirect(route('admin.web.products.edit', $product));
        $this->assertSame($category->id, $product->refresh()->product_category_id);
        $this->assertSame($brand->id, $product->brand_id);
        $this->from('/admin/products/create')->post('/admin/products', [
            'name' => 'Invalid', 'slug' => 'invalid', 'base_sku' => 'INVALID',
            'product_category_id' => $category->id, 'brand_id' => $brand->id,
            'variants' => [['variant_name' => 'Default', 'unit_id' => $unit->id, 'sellable_retail' => '1', 'sellable_dealer' => '0']],
        ])->assertSessionHasErrors(['product_category_id', 'brand_id']);
    }

    public function test_category_list_eager_loads_parents_and_counts_products_without_per_row_queries(): void
    {
        $this->withoutVite();
        $parent = ProductCategory::factory()->create();
        ProductCategory::factory()->count(6)->create(['parent_id' => $parent->id]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs(User::factory()->admin()->create())->get('/admin/categories')->assertOk();

        $categoryQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `product_categories`'));
        $this->assertLessThanOrEqual(3, count($categoryQueries));
    }
}
