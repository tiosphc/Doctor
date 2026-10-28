<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AdminProductWriteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBladeProductWriteTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_customer_cannot_open_or_submit_product_forms(): void
    {
        $category = ProductCategory::factory()->create();
        $unit = Unit::factory()->create();
        $product = Product::factory()->create();
        $data = $this->productData($category, $unit);

        $this->get('/admin/products/create')->assertRedirect('/login');
        $this->post('/admin/products', $data)->assertRedirect('/login');
        $this->get('/admin/products/'.$product->id.'/edit')->assertRedirect('/login');
        $this->actingAs(User::factory()->customer()->create());
        $this->get('/admin/products/create')->assertForbidden();
        $this->post('/admin/products', $data)->assertForbidden();
        $this->get('/admin/products/'.$product->id.'/edit')->assertForbidden();
        $this->assertDatabaseMissing('products', ['slug' => 'blade-serum']);
    }

    public function test_variant_and_image_write_routes_require_admin(): void
    {
        $product = Product::factory()->create();
        $unit = Unit::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['unit_id' => $unit->id]);
        $variantData = ['variant_name' => 'Lọ mới', 'unit_id' => $unit->id];

        $this->post('/admin/products/'.$product->id.'/variants', $variantData)->assertRedirect('/login');
        $this->patch('/admin/products/'.$product->id.'/variants/'.$variant->id, ['status' => 'inactive'])->assertRedirect('/login');
        $this->post('/admin/products/'.$product->id.'/images', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->customer()->create());
        $this->post('/admin/products/'.$product->id.'/variants', $variantData)->assertForbidden();
        $this->patch('/admin/products/'.$product->id.'/variants/'.$variant->id, ['status' => 'inactive'])->assertForbidden();
        $this->post('/admin/products/'.$product->id.'/images', [])->assertForbidden();
        $this->assertSame('active', $variant->refresh()->status);
    }

    public function test_admin_creates_product_with_multiple_skus_units_channels_and_image(): void
    {
        $this->withoutVite();
        Storage::fake('public');
        $category = ProductCategory::factory()->create();
        $unit = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products/create')
            ->assertOk()->assertViewIs('admin.products.create');

        $data = $this->productData($category, $unit);
        $data['variants'][] = [
            'variant_name' => 'Lọ lớn', 'unit_id' => $otherUnit->id,
            'sellable_retail' => '0', 'sellable_dealer' => '1',
        ];
        $data['images'] = [UploadedFile::fake()->image('serum.jpg')];
        $response = $this->post('/admin/products', $data);

        $product = Product::query()->where('slug', 'blade-serum')->firstOrFail();
        $response->assertRedirect(route('admin.web.products.show', $product));
        $this->assertSame(2, $product->variants()->count());
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id, 'sku' => 'BLADE-LO-NHO', 'unit_id' => $unit->id,
            'sellable_retail' => 1, 'sellable_dealer' => 0,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id, 'sku' => 'BLADE-LO-LON', 'unit_id' => $otherUnit->id,
            'sellable_retail' => 0, 'sellable_dealer' => 1,
        ]);
        $image = $product->images()->firstOrFail();
        $this->assertTrue($image->is_primary);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_duplicate_sku_keeps_nested_input_without_leaving_product(): void
    {
        $category = ProductCategory::factory()->create();
        $unit = Unit::factory()->create();
        ProductVariant::factory()->create(['sku' => 'BLADE-LO-NHO']);
        $data = $this->productData($category, $unit);
        $data['variants'][] = [
            'variant_name' => 'Lọ lớn', 'unit_id' => $unit->id,
            'sellable_retail' => '1', 'sellable_dealer' => '0',
        ];
        $this->actingAs(User::factory()->admin()->create())
            ->from('/admin/products/create')->post('/admin/products', $data)
            ->assertRedirect('/admin/products/create')
            ->assertSessionHasErrors('variants.0.sku')
            ->assertSessionHasInput('variants.1.variant_name', 'Lọ lớn');
        $this->assertDatabaseMissing('products', ['slug' => 'blade-serum']);
    }

    public function test_product_and_variants_roll_back_together_when_a_sku_insert_fails(): void
    {
        $category = ProductCategory::factory()->create();
        $unit = Unit::factory()->create();
        $data = $this->productData($category, $unit);
        unset($data['variants']);
        $data['default_unit_id'] = $unit->id;
        $variants = [
            ['variant_name' => 'A', 'sku' => 'SAME-SKU', 'unit_id' => $unit->id],
            ['variant_name' => 'B', 'sku' => 'SAME-SKU', 'unit_id' => $unit->id],
        ];

        try {
            app(AdminProductWriteService::class)->create($data, $variants);
            $this->fail('Expected a database uniqueness error.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('products', ['slug' => 'blade-serum']);
            $this->assertDatabaseMissing('product_variants', ['sku' => 'SAME-SKU']);
        }
    }

    public function test_admin_edits_product_and_variant_without_mass_assigning_other_fields(): void
    {
        $this->withoutVite();
        $category = ProductCategory::factory()->create();
        $unit = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();
        $product = Product::factory()->create(['product_category_id' => $category->id]);
        $variant = ProductVariant::factory()->for($product)->create(['unit_id' => $unit->id]);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()->assertSee($variant->sku)->assertSee($unit->name);

        $this->patch('/admin/products/'.$product->id, [
            'name' => 'Serum mới', 'slug' => 'serum-moi', 'product_category_id' => $category->id,
            'status' => 'inactive', 'created_by' => 999,
        ])->assertRedirect(route('admin.web.products.edit', $product));
        $this->assertSame('Serum mới', $product->refresh()->name);
        $this->assertSame('inactive', $product->status);

        $this->patch('/admin/products/'.$product->id.'/variants/'.$variant->id, [
            'variant_name' => 'Lọ 30 ml', 'sku' => 'EDIT-30', 'unit_id' => $otherUnit->id,
            'sellable_retail' => '0', 'sellable_dealer' => '1', 'status' => 'active', 'product_id' => 999,
        ])->assertRedirect(route('admin.web.products.edit', $product));
        $variant->refresh();
        $this->assertSame('EDIT-30', $variant->sku);
        $this->assertSame($otherUnit->id, $variant->unit_id);
        $this->assertFalse($variant->sellable_retail);
        $this->assertTrue($variant->sellable_dealer);
        $this->assertSame($product->id, $variant->product_id);
    }

    public function test_image_primary_reorder_and_delete_use_public_storage(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/products/'.$product->id.'/images', ['image' => UploadedFile::fake()->image('one.jpg')])
            ->assertRedirect(route('admin.web.products.edit', $product));
        $first = $product->images()->firstOrFail();
        $this->assertTrue($first->is_primary);
        $this->post('/admin/products/'.$product->id.'/images', ['image' => UploadedFile::fake()->image('two.png')])
            ->assertRedirect(route('admin.web.products.edit', $product));
        $second = $product->images()->orderByDesc('id')->firstOrFail();
        $this->patch('/admin/products/'.$product->id.'/images/'.$second->id, ['is_primary' => '1', 'sort_order' => '0'])
            ->assertRedirect(route('admin.web.products.edit', $product));
        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->refresh()->is_primary);
        $this->assertSame(0, $second->sort_order);
        $this->delete('/admin/products/'.$product->id.'/images/'.$second->id)
            ->assertRedirect(route('admin.web.products.edit', $product));
        $this->assertTrue($first->refresh()->is_primary);
        Storage::disk('public')->assertMissing($second->path);
        Storage::disk('public')->assertExists($first->path);
    }

    public function test_admin_adds_variant_and_cannot_change_sku_or_unit_after_stock_history(): void
    {
        $product = Product::factory()->create();
        $unit = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/products/'.$product->id.'/variants', [
            'variant_name' => 'Lọ 50 ml', 'unit_id' => $unit->id,
            'sellable_retail' => '1', 'sellable_dealer' => '0',
        ])->assertRedirect(route('admin.web.products.edit', $product));
        $variant = $product->variants()->firstOrFail();
        $this->assertSame($product->product_code.'-LO-50-ML', $variant->sku);
        $this->assertSame($unit->id, $variant->unit_id);
        DB::table('inventory_balances')->insert([
            'product_variant_id' => $variant->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'on_hand_quantity' => 1,
            'reserved_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->patch('/admin/products/'.$product->id.'/variants/'.$variant->id, [
            'sku' => 'BLADE-OTHER', 'unit_id' => $otherUnit->id,
        ])->assertSessionHasErrors('variant_'.$variant->id);
        $this->assertSame($product->product_code.'-LO-50-ML', $variant->refresh()->sku);
        $this->assertSame($unit->id, $variant->unit_id);
        $this->patch('/admin/products/'.$product->id.'/variants/'.$variant->id, [
            'status' => 'inactive',
        ])->assertRedirect(route('admin.web.products.edit', $product));
        $this->assertSame('inactive', $variant->refresh()->status);
    }

    public function test_image_upload_rejects_non_image_and_foreign_image_routes(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ])->assertSessionHasErrors('image');
        $this->assertSame(0, $product->images()->count());
        $this->post('/admin/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('valid.jpg'),
        ])->assertRedirect(route('admin.web.products.edit', $product));
        $image = $product->images()->firstOrFail();
        $this->patch('/admin/products/'.$other->id.'/images/'.$image->id, ['sort_order' => '3'])->assertNotFound();
        $this->delete('/admin/products/'.$other->id.'/images/'.$image->id)->assertNotFound();
        Storage::disk('public')->assertExists($image->path);
    }

    /** @return array<string, mixed> */
    private function productData(ProductCategory $category, Unit $unit): array
    {
        return [
            'name' => 'Blade Serum', 'slug' => 'blade-serum', 'base_sku' => 'BLADE', 'product_category_id' => $category->id,
            'status' => 'active', 'track_inventory' => '1', 'can_be_gift' => '0', 'gift_only' => '0',
            'variants' => [[
                'variant_name' => 'Lọ nhỏ', 'unit_id' => $unit->id,
                'sellable_retail' => '1', 'sellable_dealer' => '0',
            ]],
        ];
    }
}
