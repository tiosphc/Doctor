<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RetailPricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductWizardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'name' => 'Serum Wizard', 'sku' => 'SERUM-WIZ',
            'product_category_id' => ProductCategory::factory()->create()->id,
            'brand_id' => null, 'unit_id' => Unit::factory()->create()->id,
            'sellable_retail' => true, 'sellable_dealer' => false,
            'description' => null, 'youtube_videos' => [],
            'has_variants' => false, 'attributes' => [], 'variants' => [],
            'retail_price' => '120000', 'retail_breaks' => [], 'dealer_rules' => [],
            'track_inventory' => false, 'warehouse_id' => null, 'initial_stock' => '0',
            'low_stock_threshold' => null, 'weight' => null, 'length' => null,
            'width' => null, 'height' => null, 'usage_instructions' => null,
        ];
    }

    private function draft(array $data): int
    {
        return $this->postJson('/api/admin/product-wizard/drafts', [
            'wizard_key' => (string) Str::uuid(), 'data' => $data,
        ])->assertCreated()->json('data.id');
    }

    private function image(int $productId): int
    {
        Storage::fake('public');

        return $this->post("/api/admin/products/{$productId}/images", [
            'image' => UploadedFile::fake()->createWithContent('serum.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==')),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_partial_draft_is_saved_and_repeated_key_does_not_create_another_product(): void
    {
        $this->admin();
        $key = (string) Str::uuid();
        $body = ['wizard_key' => $key, 'data' => ['name' => 'Chưa hoàn thiện']];
        $id = $this->postJson('/api/admin/product-wizard/drafts', $body)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.product_category_id', null)
            ->json('data.id');
        $this->postJson('/api/admin/product-wizard/drafts', $body)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_unfinished_wizard_product_is_hidden_from_admin_catalog_and_can_be_discarded(): void
    {
        $this->admin();
        $id = $this->draft(['name' => 'Unfinished product']);

        $this->getJson('/api/admin/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/products?search=Unfinished')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->deleteJson("/api/admin/products/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_wizard_requires_image_and_rejects_invalid_fields(): void
    {
        $this->admin();
        $data = $this->data();
        $id = $this->draft($data);
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data.images');
        $this->image($id);
        $invalid = $data;
        $invalid['name'] = '   ';
        $invalid['sellable_retail'] = false;
        $invalid['youtube_videos'] = ['https://example.com/watch?v=not-youtube'];
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors(['data.name', 'data.channels', 'data.youtube_videos.0']);
        $invalid = $data;
        $invalid['retail_price'] = '-1';
        $invalid['retail_breaks'] = [['min_quantity' => 10, 'unit_price' => 100000], ['min_quantity' => 5, 'unit_price' => 90000]];
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors(['data.retail_price', 'data.retail_breaks']);
        $invalid = $data;
        $invalid['initial_stock'] = '1.001';
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors('data.initial_stock');
    }

    public function test_simple_retail_product_completes_once_with_real_price(): void
    {
        $this->admin();
        $data = $this->data();
        $data['weight'] = '0.125';
        $data['length'] = '12';
        $data['width'] = '4';
        $data['height'] = '3';
        $id = $this->draft(['name' => $data['name']]);
        $this->image($id);
        $response = $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.variants.0.sku', 'SERUM-WIZ');
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_variants', [
            'id' => $response->json('data.variants.0.id'),
            'weight' => '0.125', 'length' => '12.000', 'width' => '4.000', 'height' => '3.000',
        ]);
        $this->assertDatabaseCount('price_lists', 1);
        $this->assertDatabaseCount('price_list_items', 1);
        $this->assertSame('120000.00', app(RetailPricingService::class)->resolve(ProductVariant::findOrFail($response->json('data.variants.0.id')))['unit_price']);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_zero_retail_price_is_accepted_when_explicitly_entered(): void
    {
        $this->admin();
        $data = $this->data();
        $data['sku'] = 'FREE-RETAIL';
        $data['retail_price'] = '0';
        $id = $this->draft($data);
        $this->image($id);

        $response = $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertOk();

        $this->assertSame('0.00', app(RetailPricingService::class)->resolve(ProductVariant::findOrFail($response->json('data.variants.0.id')))['unit_price']);
    }

    public function test_variant_dealer_moq_and_opening_stock_use_existing_ledgers(): void
    {
        $this->admin();
        $data = $this->data();
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE']);
        $warehouse = Warehouse::factory()->create();
        $data['sku'] = 'SERUM-MULTI';
        $data['has_variants'] = true;
        $data['attributes'] = [['name' => 'Size', 'values' => ['S', 'M']]];
        $data['variants'] = [
            ['sku' => 'SERUM-S', 'specifications' => ['Size' => 'S'], 'initial_stock' => '2', 'retail_price_override' => '130000'],
            ['sku' => 'SERUM-M', 'specifications' => ['Size' => 'M'], 'initial_stock' => '3'],
        ];
        $data['sellable_dealer'] = true;
        $data['dealer_rules'] = [
            ['tier_id' => $tier->id, 'sku' => 'SERUM-S', 'min_quantity' => 10, 'unit_price' => '90000'],
            ['tier_id' => $tier->id, 'sku' => 'SERUM-M', 'min_quantity' => 20, 'unit_price' => '80000'],
        ];
        $data['track_inventory'] = true;
        $data['warehouse_id'] = $warehouse->id;
        $id = $this->draft($data);
        $imageId = $this->image($id);
        $data['variants'][0]['image_id'] = $imageId;
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertOk()->assertJsonCount(2, 'data.variants');
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'on_hand_quantity' => '2.000']);
        $this->assertDatabaseHas('product_images', ['id' => $imageId, 'product_variant_id' => ProductVariant::where('sku', 'SERUM-S')->value('id')]);
        $this->assertDatabaseHas('price_lists', ['pricing_context' => 'dealer', 'scope_type' => 'tier', 'dealer_tier_id' => $tier->id]);
        $this->assertSame('120000.00', app(RetailPricingService::class)->resolve(ProductVariant::where('sku', 'SERUM-M')->firstOrFail(), quantity: '10')['unit_price']);
        $this->assertDatabaseHas('price_list_items', ['product_variant_id' => ProductVariant::where('sku', 'SERUM-S')->value('id'), 'minimum_quantity' => '10.000', 'unit_price' => '90000.00']);
        $this->assertSame(0, DB::table('inventory_balances')->where('on_hand_quantity', '<', 0)->count());
    }

    public function test_named_variants_use_product_unit_and_keep_prices_and_opening_stock(): void
    {
        $this->admin();
        $data = $this->data();
        $warehouse = Warehouse::factory()->create();
        $data['sku'] = 'SERUM-LO';
        $data['has_variants'] = true;
        $data['variants'] = [
            ['sku' => 'SERUM-LO-5ML', 'variant_name' => '5ml', 'specifications' => [], 'initial_stock' => '2'],
            ['sku' => 'SERUM-LO-10ML', 'variant_name' => '10ml', 'specifications' => [], 'initial_stock' => '3', 'retail_price_override' => '150000'],
        ];
        $data['track_inventory'] = true;
        $data['warehouse_id'] = $warehouse->id;
        $id = $this->draft($data);
        $this->image($id);

        $duplicateName = $data;
        $duplicateName['variants'][1]['variant_name'] = '5ML';
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $duplicateName])
            ->assertUnprocessable()->assertJsonValidationErrors('data.variants.1.variant_name');

        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertOk()->assertJsonCount(2, 'data.variants');

        $first = ProductVariant::where('sku', 'SERUM-LO-5ML')->firstOrFail();
        $second = ProductVariant::where('sku', 'SERUM-LO-10ML')->firstOrFail();
        $this->assertSame('5ml', $first->variant_name);
        $this->assertSame('10ml', $second->variant_name);
        $this->assertSame($data['unit_id'], $first->unit_id);
        $this->assertSame($data['unit_id'], $second->unit_id);
        $this->assertSame('120000.00', app(RetailPricingService::class)->resolve($first)['unit_price']);
        $this->assertSame('150000.00', app(RetailPricingService::class)->resolve($second)['unit_price']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $first->id, 'on_hand_quantity' => '2.000']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $second->id, 'on_hand_quantity' => '3.000']);
    }

    public function test_duplicate_sku_and_duplicate_variant_value_are_rejected(): void
    {
        $this->admin();
        $data = $this->data();
        $id = $this->draft($data);
        $this->image($id);
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])->assertOk();
        $other = $this->draft(['name' => 'Other']);
        $this->image($other);
        $this->postJson("/api/admin/product-wizard/drafts/{$other}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data.sku');
        $data['sku'] = 'OTHER-SKU';
        $data['has_variants'] = true;
        $data['attributes'] = [['name' => 'Size', 'values' => ['S', 's']]];
        $data['variants'] = [];
        $this->postJson("/api/admin/product-wizard/drafts/{$other}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data.attributes.0.values.1');
    }

    public function test_malformed_nested_payload_returns_validation_errors_instead_of_server_errors(): void
    {
        $this->admin();
        $data = $this->data();
        $id = $this->draft($data);
        $this->image($id);
        $data['youtube_videos'] = 'invalid';
        $data['variants'] = 'invalid';
        $data['retail_breaks'] = 'invalid';
        $data['dealer_rules'] = 'invalid';
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors([
                'data.youtube_videos', 'data.variants', 'data.retail_breaks', 'data.dealer_rules',
            ]);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_dealer_only_product_requires_valid_tier_and_moq_without_retail_fallback(): void
    {
        $this->admin();
        $data = $this->data();
        $data['sku'] = 'DEALER-ONLY';
        $data['sellable_retail'] = false;
        $data['sellable_dealer'] = true;
        $data['retail_price'] = null;
        $id = $this->draft($data);
        $this->image($id);
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data.dealer_rules');
        $data['dealer_rules'] = [['tier_id' => 999999, 'sku' => 'DEALER-ONLY', 'min_quantity' => -1, 'unit_price' => -1]];
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors([
                'data.dealer_rules.0.tier_id', 'data.dealer_rules.0.min_quantity', 'data.dealer_rules.0.unit_price',
            ]);
        $tier = DealerTier::factory()->create();
        $data['dealer_rules'] = [
            ['tier_id' => $tier->id, 'sku' => 'DEALER-ONLY', 'min_quantity' => 10, 'unit_price' => 90000],
            ['tier_id' => $tier->id, 'sku' => 'DEALER-ONLY', 'min_quantity' => 10, 'unit_price' => 80000],
        ];
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data.dealer_rules.1.sku');
        array_pop($data['dealer_rules']);
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])->assertOk();
        $this->assertDatabaseHas('price_lists', ['pricing_context' => 'dealer', 'dealer_tier_id' => $tier->id]);
        $this->assertDatabaseMissing('price_lists', ['pricing_context' => 'retail']);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_wizard_draft_is_owner_scoped_and_cannot_be_activated_through_legacy_update(): void
    {
        $this->admin();
        $data = $this->data();
        $id = $this->draft($data);
        $this->putJson("/api/admin/products/{$id}", [
            'name' => 'Bypass', 'product_category_id' => $data['product_category_id'],
            'brand_id' => null, 'status' => 'active',
        ])->assertStatus(409);
        $this->assertDatabaseHas('products', ['id' => $id, 'status' => 'draft']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/admin/product-wizard/drafts/{$id}")->assertNotFound();
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])->assertNotFound();
    }

    public function test_completion_retry_with_changed_payload_conflicts_without_duplicate_writes(): void
    {
        $this->admin();
        $data = $this->data();
        $id = $this->draft($data);
        $this->image($id);
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])->assertOk();
        $data['name'] = 'Changed name';
        $this->postJson("/api/admin/product-wizard/drafts/{$id}/complete", ['data' => $data])
            ->assertStatus(409)->assertJsonPath('code', 'WIZARD_ALREADY_COMPLETED');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('price_list_items', 1);
    }
}
