<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RetailPricingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeProductDraftTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, mixed> */
    private function wizardData(): array
    {
        return [
            'name' => 'Blade Draft Serum', 'sku' => 'BLADE-DRAFT',
            'product_category_id' => ProductCategory::factory()->create()->id,
            'brand_id' => null, 'unit_id' => Unit::factory()->create()->id,
            'sellable_retail' => '1', 'sellable_dealer' => '0',
            'can_be_gift' => '0', 'gift_only' => '0',
            'description' => null, 'youtube_videos' => [],
            'has_variants' => '0', 'variants' => [], 'retail_price' => '120000',
            'dealer_rules' => [], 'track_inventory' => '1',
            'warehouse_id' => null, 'initial_stock' => '0',
        ];
    }

    private function draft(User $owner, array $data = []): Product
    {
        return Product::factory()->create([
            'status' => 'draft', 'wizard_key' => (string) Str::uuid(),
            'wizard_owner_user_id' => $owner->id, 'wizard_data' => $data,
        ]);
    }

    public function test_draft_routes_require_admin_and_owner(): void
    {
        $owner = User::factory()->admin()->create();
        $draft = $this->draft($owner);
        $list = route('admin.web.products.drafts.index');
        $edit = route('admin.web.products.drafts.edit', $draft);
        $save = route('admin.web.products.drafts.save', $draft);
        $complete = route('admin.web.products.drafts.complete', $draft);

        $this->get($list)->assertRedirect('/login');
        $this->get($edit)->assertRedirect('/login');
        $this->post($save, ['data' => []])->assertRedirect('/login');
        $this->post($complete, ['data' => []])->assertRedirect('/login');
        $this->actingAs(User::factory()->customer()->create());
        $this->get($list)->assertForbidden();
        $this->get($edit)->assertForbidden();
        $this->post($complete, ['data' => []])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $this->get($edit)->assertNotFound();
        $this->post($save, ['data' => []])->assertNotFound();
        $this->post($complete, ['data' => []])->assertNotFound();
        $this->actingAs($owner)->get($edit)->assertOk()->assertViewIs('admin.products.drafts.edit');
    }

    public function test_list_search_pagination_and_product_links(): void
    {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);
        $target = $this->draft($owner);
        $target->update(['name' => 'Target Serum', 'product_code' => 'PRD-TARGET']);
        $otherOwner = $this->draft(User::factory()->admin()->create());
        $active = Product::factory()->create(['name' => 'Active Serum']);
        ProductImage::factory()->for($target)->create();
        for ($index = 0; $index < 20; $index++) {
            $this->draft($owner);
        }

        $this->get(route('admin.web.products.drafts.index'))
            ->assertOk()->assertViewHas('drafts', fn ($drafts) => $drafts->total() === 21 && $drafts->count() === 20)
            ->assertDontSee($otherOwner->product_code)->assertDontSee($active->name);
        $this->get(route('admin.web.products.drafts.index', ['search' => 'PRD-TARGET']))
            ->assertOk()->assertSee($target->name)->assertSee(route('admin.web.products.drafts.edit', $target));
        $this->get(route('admin.web.products.drafts.index', ['page' => 2]))
            ->assertOk()->assertViewHas('drafts', fn ($drafts) => $drafts->currentPage() === 2 && $drafts->count() === 1);
        $this->get(route('admin.web.products.index', ['status' => 'draft', 'search' => 'PRD-TARGET']))
            ->assertOk()->assertSee($target->name);
        $this->get(route('admin.web.products.show', $target))->assertOk()
            ->assertSee(route('admin.web.products.drafts.edit', $target))
            ->assertDontSee(route('admin.web.products.edit', $target));
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.web.products.show', $target))->assertOk()
            ->assertDontSee(route('admin.web.products.drafts.edit', $target))
            ->assertDontSee(route('admin.web.products.edit', $target));
    }

    public function test_resume_reuses_saved_data_and_images_and_rejects_active_product(): void
    {
        $owner = User::factory()->admin()->create();
        $data = $this->wizardData();
        $data['has_variants'] = true;
        $data['attributes'] = [['name' => 'Size', 'values' => ['5ml']]];
        $data['variants'] = [['sku' => 'LEGACY-5ML', 'specifications' => ['Size' => '5ml'], 'initial_stock' => '2']];
        $draft = $this->draft($owner, $data);
        $image = ProductImage::factory()->for($draft)->create();

        $this->actingAs($owner)->get(route('admin.web.products.drafts.edit', $draft))
            ->assertOk()->assertSee($image->id)->assertSee('BLADE-DRAFT-5ML')
            ->assertViewHas('data', fn ($loaded) => $loaded['variants'][0]['variant_name'] === '5ml');
        $this->get(route('admin.web.products.drafts.edit', Product::factory()->create()))->assertNotFound();
    }

    public function test_save_persists_data_and_validation_preserves_old_input(): void
    {
        $owner = User::factory()->admin()->create();
        $draft = $this->draft($owner);
        $this->actingAs($owner);
        $data = $this->wizardData();
        $data['has_variants'] = '1';
        $data['variants'] = [['variant_name' => '30ml', 'initial_stock' => '0']];
        $this->post(route('admin.web.products.drafts.save', $draft), ['data' => $data])
            ->assertRedirect(route('admin.web.products.drafts.edit', $draft));
        $this->assertSame('BLADE-DRAFT-30ML', $draft->refresh()->wizard_data['variants'][0]['sku']);
        $this->get(route('admin.web.products.drafts.edit', $draft))
            ->assertOk()->assertViewHas('data', fn ($loaded) => $loaded['variants'][0]['variant_name'] === '30ml');

        $invalid = $data;
        $invalid['unit_id'] = 999999;
        $invalid['variants'][0]['variant_name'] = 'Changed but invalid';
        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $invalid])
            ->assertRedirect()->assertSessionHasErrors('data.unit_id');
        $this->get(route('admin.web.products.drafts.edit', $draft))
            ->assertOk()->assertViewHas('data', fn ($loaded) => $loaded['variants'][0]['variant_name'] === 'Changed but invalid');
        $this->assertSame('30ml', $draft->refresh()->wizard_data['variants'][0]['variant_name']);
    }

    public function test_draft_images_can_be_managed_in_blade(): void
    {
        Storage::fake('public');
        $owner = User::factory()->admin()->create();
        $draft = $this->draft($owner);
        $this->actingAs($owner);
        $this->post(route('admin.web.products.images.store', $draft), ['image' => UploadedFile::fake()->image('draft.png')])
            ->assertRedirect(route('admin.web.products.drafts.edit', $draft));
        $image = $draft->images()->firstOrFail();
        $second = ProductImage::factory()->for($draft)->create(['is_primary' => false]);
        $this->patch(route('admin.web.products.images.update', [$draft, $second]), ['is_primary' => '1'])
            ->assertRedirect(route('admin.web.products.drafts.edit', $draft));
        $this->assertTrue((bool) $second->refresh()->is_primary);
        $this->delete(route('admin.web.products.images.destroy', [$draft, $image]))
            ->assertRedirect(route('admin.web.products.drafts.edit', $draft));
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.web.products.images.destroy', [$draft, $second]))->assertNotFound();
    }

    public function test_complete_creates_skus_prices_dynamic_tier_and_only_positive_opening(): void
    {
        $owner = User::factory()->admin()->create();
        $data = $this->wizardData();
        $tier = DealerTier::factory()->create(['code' => 'CUSTOM-LAB']);
        $warehouse = Warehouse::factory()->create();
        $data['sellable_dealer'] = '1';
        $data['has_variants'] = '1';
        $data['warehouse_id'] = $warehouse->id;
        $data['variants'] = [
            ['variant_name' => '5ml', 'initial_stock' => '2', 'retail_price_override' => '130000'],
            ['variant_name' => '10ml', 'initial_stock' => '0', 'retail_price_override' => ''],
        ];
        $data['dealer_rules'] = [
            ['tier_id' => $tier->id, 'variant_index' => '0', 'min_quantity' => '10', 'unit_price' => '90000'],
            ['tier_id' => $tier->id, 'variant_index' => '1', 'min_quantity' => '20', 'unit_price' => '80000'],
        ];
        $draft = $this->draft($owner);
        ProductImage::factory()->for($draft)->create();
        $this->actingAs($owner);
        $this->post(route('admin.web.products.drafts.save', $draft), ['data' => $data])->assertRedirect();
        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $data])
            ->assertRedirect(route('admin.web.products.show', $draft));

        $this->assertSame('active', $draft->refresh()->status);
        $this->assertSame(2, $draft->variants()->count());
        $first = ProductVariant::where('sku', 'BLADE-DRAFT-5ML')->firstOrFail();
        $second = ProductVariant::where('sku', 'BLADE-DRAFT-10ML')->firstOrFail();
        $this->assertSame($draft->id, $first->product_id);
        $this->assertSame($data['unit_id'], $first->unit_id);
        $this->assertTrue($first->sellable_retail && $first->sellable_dealer && $first->track_inventory);
        $this->assertSame('130000.00', app(RetailPricingService::class)->resolve($first)['unit_price']);
        $this->assertSame('120000.00', app(RetailPricingService::class)->resolve($second)['unit_price']);
        $this->assertDatabaseHas('price_lists', ['pricing_context' => 'dealer', 'dealer_tier_id' => $tier->id]);
        $this->assertDatabaseHas('price_list_items', ['product_variant_id' => $first->id, 'minimum_quantity' => '10.000', 'unit_price' => '90000.00']);
        $this->assertDatabaseHas('price_list_items', ['product_variant_id' => $second->id, 'minimum_quantity' => '20.000', 'unit_price' => '80000.00']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $first->id, 'on_hand_quantity' => '2.000']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseMissing('stock_movements', ['product_variant_id' => $second->id]);
        $this->assertNotNull($draft->refresh()->wizard_key);

        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $data])
            ->assertRedirect(route('admin.web.products.show', $draft));
        $this->assertDatabaseCount('product_variants', 2);
        $changed = $data;
        $changed['name'] = 'Changed';
        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $changed])
            ->assertRedirect(route('admin.web.products.show', $draft))->assertSessionHasErrors('data');
    }

    public function test_invalid_price_and_inactive_warehouse_do_not_activate_draft(): void
    {
        $owner = User::factory()->admin()->create();
        $data = $this->wizardData();
        $data['warehouse_id'] = Warehouse::factory()->create(['status' => 'inactive'])->id;
        $data['initial_stock'] = '1';
        $data['retail_price'] = '-1';
        $draft = $this->draft($owner);
        ProductImage::factory()->for($draft)->create();
        $this->actingAs($owner)->post(route('admin.web.products.drafts.complete', $draft), ['data' => $data])
            ->assertRedirect()->assertSessionHasErrors(['data.retail_price', 'data.warehouse_id']);
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_missing_image_and_duplicate_sku_keep_draft_unchanged(): void
    {
        $owner = User::factory()->admin()->create();
        $data = $this->wizardData();
        $draft = $this->draft($owner);
        $this->actingAs($owner);
        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $data])
            ->assertRedirect()->assertSessionHasErrors('data.images');
        $this->assertDatabaseCount('product_variants', 0);

        ProductImage::factory()->for($draft)->create();
        ProductVariant::factory()->create(['sku' => 'BLADE-DRAFT']);
        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $data])
            ->assertRedirect()->assertSessionHasErrors('data.sku');
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_orphan_draft_is_visible_with_issue_and_cannot_be_completed(): void
    {
        $owner = User::factory()->admin()->create();
        $draft = $this->draft($owner);
        $draft->update(['wizard_key' => null]);
        $this->actingAs($owner)->get(route('admin.web.products.drafts.edit', $draft))
            ->assertOk()->assertSee('thiếu khóa wizard')->assertDontSee('Hoàn tất &amp; kích hoạt', false);
        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $this->wizardData()])
            ->assertNotFound();
        $this->assertSame('draft', $draft->refresh()->status);
    }

    public function test_blade_product_and_variant_writes_cannot_bypass_draft_completion(): void
    {
        $owner = User::factory()->admin()->create();
        $data = $this->wizardData();
        $draft = $this->draft($owner, $data);
        $this->actingAs($owner);
        $variantData = ['variant_name' => '5ml', 'sku' => 'BLADE-DRAFT-5ML', 'unit_id' => $data['unit_id']];

        $this->post(route('admin.web.products.variants.store', $draft), $variantData)
            ->assertRedirect(route('admin.web.products.drafts.edit', $draft))
            ->assertSessionHasErrors('product');
        $this->patch(route('admin.web.products.update', $draft), ['status' => 'active'])
            ->assertRedirect(route('admin.web.products.drafts.edit', $draft))
            ->assertSessionHasErrors('product');
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertDatabaseCount('product_variants', 0);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.web.products.variants.store', $draft), $variantData)->assertNotFound();
    }

    public function test_inventory_failure_after_pricing_rolls_back_entire_completion(): void
    {
        $owner = User::factory()->admin()->create();
        $data = $this->wizardData();
        $warehouse = Warehouse::factory()->create();
        $data['warehouse_id'] = $warehouse->id;
        $data['initial_stock'] = '1';
        $draft = $this->draft($owner);
        ProductImage::factory()->for($draft)->create();
        $this->actingAs($owner);
        $changedWarehouse = false;
        DB::listen(function (QueryExecuted $query) use ($warehouse, &$changedWarehouse): void {
            if (! $changedWarehouse && str_contains(strtolower($query->sql), 'insert into `price_list_items`')) {
                $changedWarehouse = true;
                DB::table('warehouses')->where('id', $warehouse->id)->update(['status' => 'inactive']);
            }
        });

        $this->post(route('admin.web.products.drafts.complete', $draft), ['data' => $data])
            ->assertRedirect()->assertSessionHasErrors('data');
        $this->assertTrue($changedWarehouse);
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame('active', $warehouse->refresh()->status);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('price_lists', 0);
        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }
}
