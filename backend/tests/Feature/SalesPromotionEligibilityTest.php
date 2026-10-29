<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesPromotionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesPromotionEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_both_scope_tier_restriction_applies_only_to_dealer_for_percentage_discounts(): void
    {
        $buyer = User::factory()->customer()->create();
        $gold = DealerTier::factory()->create();
        $silver = DealerTier::factory()->create();
        $variant = ProductVariant::factory()->create();
        $promotion = SalesPromotion::factory()->create(['sales_scope' => 'both',
            'discount_type' => 'percentage', 'discount_value' => '10.00']);
        $promotion->dealerTiers()->attach($gold->id);
        $lines = [['product_variant_id' => $variant->id, 'product_id' => $variant->product_id,
            'amount' => '100.00', 'quantity' => '1']];
        $service = app(SalesPromotionService::class);
        $this->assertSame('10.00', $service->quote($promotion->code, 'retail', $buyer->id, null, '100.00', $lines)['discount_amount']);
        $this->assertSame('10.00', $service->quote($promotion->code, 'dealer', $buyer->id, 1, '100.00', $lines,
            effectiveTierId: $gold->id)['discount_amount']);
        try {
            $service->quote($promotion->code, 'dealer', $buyer->id, 1, '100.00', $lines,
                effectiveTierId: $silver->id);
            $this->fail('Silver dealer must not receive a Gold promotion.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_NOT_APPLICABLE_TO_TIER', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_admin_ignores_tiers_for_retail_promotion_and_preserves_dealer_tiers(): void
    {
        $gold = DealerTier::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $body = ['code' => 'TIER-FIXED', 'name' => 'Tier promotion',
            'discount_type' => 'percentage', 'discount_value' => '20.00',
            'sales_scope' => 'retail', 'dealer_tier_ids' => [$gold->id], 'status' => 'active',
            'product_ids' => [ProductVariant::factory()->create()->product_id]];
        $id = $this->postJson('/api/admin/sales-promotions', $body)->assertCreated()->json('data.id');
        $this->assertDatabaseMissing('sales_promotion_dealer_tiers', ['sales_promotion_id' => $id]);
        $this->patchJson("/api/admin/sales-promotions/$id", [...$body, 'sales_scope' => 'both'])
            ->assertOk()->assertJsonPath('data.dealer_tiers.0.id', $gold->id);
    }

    public function test_dealer_offers_only_include_products_in_priced_catalog_for_current_tier(): void
    {
        Warehouse::factory()->create(['is_default_sales' => true]);
        $user = User::factory()->customer()->create();
        $gold = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $gold->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $priced = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $unpriced = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $gold->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $priced->id,
            'unit_price' => '100.00', 'minimum_quantity' => '1']);
        foreach ([$priced, $unpriced] as $index => $variant) {
            $promotion = SalesPromotion::factory()->create(['name' => 'Offer '.($index + 1),
                'sales_scope' => 'dealer', 'discount_type' => 'percentage', 'discount_value' => '10.00']);
            $promotion->targets()->create(['product_id' => $variant->product_id]);
        }
        Sanctum::actingAs($user);
        $offers = $this->getJson("/api/dealer/accounts/{$account->id}/gift-promotions")
            ->assertOk()->json('data');
        $this->assertCount(1, $offers);
        $this->assertSame($priced->product->slug, $offers[0]['buy_product_slug']);
        $catalog = $this->getJson("/api/dealer/accounts/{$account->id}/products")
            ->assertOk()->json('data.0');
        $detail = $this->getJson("/api/dealer/accounts/{$account->id}/products/{$offers[0]['buy_product_slug']}")
            ->assertOk()->json('data');
        $this->assertSame($offers[0]['code'], $catalog['dealer_discount_promotion']['code']);
        $this->assertSame($catalog['dealer_discount_promotion'], $detail['dealer_discount_promotion']);
        $this->assertSame('90.00', $catalog['variants'][0]['dealer_price']['discounted_unit_price']);
        $this->assertSame($catalog['variants'][0]['dealer_price'], $detail['variants'][0]['dealer_price']);
        $this->getJson("/api/dealer/accounts/{$account->id}/products/{$unpriced->product->slug}")->assertNotFound();
    }

    public function test_dealer_catalog_and_quote_apply_fixed_amount_per_unit(): void
    {
        Warehouse::factory()->create(['is_default_sales' => true]);
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id,
            'unit_price' => '200000.00', 'minimum_quantity' => '1']);
        $promotion = SalesPromotion::factory()->create(['sales_scope' => 'dealer',
            'discount_type' => 'fixed_amount', 'discount_value' => '50000']);
        $promotion->targets()->create(['product_id' => $variant->product_id]);
        Sanctum::actingAs($user);

        $catalog = $this->getJson("/api/dealer/accounts/{$account->id}/products")
            ->assertOk()->json('data.0');
        $this->assertSame('fixed_amount', $catalog['dealer_discount_promotion']['discount_type']);
        $this->assertSame('150000.00', $catalog['variants'][0]['dealer_price']['discounted_unit_price']);
        $this->postJson("/api/dealer/accounts/{$account->id}/pricing/quote", [
            'product_variant_id' => $variant->id, 'quantity' => 2,
        ])->assertOk()->assertJsonPath('data.promotion.discount_type', 'fixed_amount')
            ->assertJsonPath('data.promotion.discount_amount', '100000.00')
            ->assertJsonPath('data.discounted_line_total', '300000.00');
    }
}
