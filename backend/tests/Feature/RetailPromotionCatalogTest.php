<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetailPromotionCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retail_catalog_exposes_only_current_matching_discounts_and_filters_offer_products(): void
    {
        $list = PriceList::factory()->create();
        $percent = ProductVariant::factory()->create();
        $fixed = ProductVariant::factory()->create();
        $plain = ProductVariant::factory()->create();
        foreach ([$percent, $fixed, $plain] as $variant) {
            $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '400000', 'minimum_quantity' => 1]);
        }
        $percentageOffer = SalesPromotion::factory()->create(['sales_scope' => 'retail',
            'discount_type' => 'percentage', 'discount_value' => '15', 'minimum_order_amount' => '0']);
        $percentageOffer->targets()->create(['product_id' => $percent->product_id]);
        $fixedOffer = SalesPromotion::factory()->create(['sales_scope' => 'both',
            'discount_type' => 'fixed_amount', 'discount_value' => '100000', 'minimum_order_amount' => '800000']);
        $fixedOffer->targets()->create(['product_category_id' => $fixed->product->product_category_id]);
        $inactive = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'status' => 'inactive']);
        $inactive->targets()->create(['product_id' => $plain->product_id]);
        $future = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'starts_at' => now()->addDay()]);
        $future->targets()->create(['product_id' => $plain->product_id]);
        $expired = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'ends_at' => now()->subDay()]);
        $expired->targets()->create(['product_id' => $plain->product_id]);
        $dealer = SalesPromotion::factory()->create(['sales_scope' => 'dealer']);
        $dealer->targets()->create(['product_id' => $plain->product_id]);

        $catalog = collect($this->getJson('/api/products')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($percentageOffer->code, $catalog[$percent->product_id]['retail_promotions'][0]['code']);
        $this->assertSame('15.00', $catalog[$percent->product_id]['retail_promotions'][0]['discount_value']);
        $this->assertSame($fixedOffer->code, $catalog[$fixed->product_id]['retail_promotions'][0]['code']);
        $this->assertSame('800000.00', $catalog[$fixed->product_id]['retail_promotions'][0]['minimum_order_amount']);
        $this->assertSame([], $catalog[$plain->product_id]['retail_promotions']);

        $offers = $this->getJson('/api/products?promotions_only=1')->assertOk();
        $this->assertSame(2, $offers->json('meta.total'));
        $this->assertEqualsCanonicalizing([$percent->product_id, $fixed->product_id],
            array_column($offers->json('data'), 'id'));
    }

    public function test_retail_gift_preview_includes_real_gift_thumbnail_and_buy_product_image(): void
    {
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        PriceList::factory()->create()->items()->create([
            'product_variant_id' => $buy->id, 'unit_price' => '340000', 'minimum_quantity' => 1,
        ]);
        ProductImage::factory()->create(['product_id' => $buy->product_id, 'path' => 'products/buy.jpg']);
        ProductImage::factory()->create(['product_id' => $gift->product_id,
            'product_variant_id' => $gift->id, 'path' => 'products/gift.jpg']);
        $promotion = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'discount_type' => 'buy_a_get_b',
            'discount_value' => '0']);
        $promotion->giftRule()->create(['buy_product_id' => $buy->product_id,
            'minimum_buy_quantity' => '1', 'gift_product_id' => $gift->product_id,
            'gift_variant_id' => $gift->id, 'gift_quantity' => '1', 'repeat_per_multiple' => false]);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $gift->id, 'quantity' => '2',
            'operation_key' => (string) Str::uuid()], User::factory()->admin()->create()->id);

        $offer = $this->getJson('/api/products?promotions_only=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame($buy->product_id, $offer['id']);
        $this->assertStringContainsString('/products/buy.jpg', $offer['images'][0]['url']);
        $this->assertSame($promotion->code, $offer['gift_promotions'][0]['code']);
        $this->assertStringContainsString('/products/gift.jpg', $offer['gift_promotions'][0]['gift_image_url']);
    }
}
