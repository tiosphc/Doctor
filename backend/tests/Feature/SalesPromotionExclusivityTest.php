<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesPromotionExclusivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retail_product_accepts_one_percentage_promotion_and_rejects_a_second_api_request(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $first = $this->postJson('/api/admin/sales-promotions', $this->discount('FIRST20', $product->id))
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('sales_promotion_targets', [
            'sales_promotion_id' => $first['id'], 'product_id' => $product->id,
        ]);

        $this->postJson('/api/admin/sales-promotions', $this->discount('SECOND10', $product->id, '10'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_ALREADY_HAS_ACTIVE_PROMOTION')
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('existing_promotion_id', $first['id']);
        $this->assertDatabaseMissing('sales_promotions', ['code' => 'SECOND10']);
    }

    public function test_expired_and_inactive_discounts_do_not_block_a_new_promotion(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $expiredProduct = Product::factory()->create();
        $inactiveProduct = Product::factory()->create();
        $expired = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'ends_at' => now()->subDay()]);
        $expired->targets()->create(['product_id' => $expiredProduct->id]);
        $inactive = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'status' => 'inactive']);
        $inactive->targets()->create(['product_id' => $inactiveProduct->id]);

        $this->postJson('/api/admin/sales-promotions', $this->discount('AFTEREXPIRY', $expiredProduct->id))
            ->assertCreated();
        $this->postJson('/api/admin/sales-promotions', $this->discount('AFTERINACTIVE', $inactiveProduct->id))
            ->assertCreated();
    }

    public function test_future_discount_windows_may_follow_each_other_but_cannot_overlap(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $first = $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('FIRSTWINDOW', $product->id),
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(3)->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('OVERLAP', $product->id),
            'starts_at' => now()->addDays(2)->toDateTimeString(),
            'ends_at' => now()->addDays(4)->toDateTimeString(),
        ])->assertStatus(409)->assertJsonPath('existing_promotion_id', $first);
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('AFTERWINDOW', $product->id),
            'starts_at' => now()->addDays(4)->toDateTimeString(),
            'ends_at' => now()->addDays(5)->toDateTimeString(),
        ])->assertCreated();
    }

    public function test_gift_promotion_can_share_a_product_with_a_percentage_discount(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true]);
        $this->postJson('/api/admin/sales-promotions', $this->discount('BUY20', $buy->product_id))
            ->assertCreated();

        $this->postJson('/api/admin/sales-promotions', [
            'code' => 'BUY2GIFT1', 'name' => 'Buy two get one', 'discount_type' => 'buy_a_get_b',
            'sales_scope' => 'retail', 'status' => 'active',
            'gift_rule' => ['buy_product_id' => $buy->product_id, 'buy_variant_id' => $buy->id,
                'minimum_buy_quantity' => 2, 'gift_product_id' => $gift->product_id,
                'gift_variant_id' => $gift->id, 'gift_quantity' => 1, 'repeat_per_multiple' => false],
        ])->assertCreated()->assertJsonPath('data.discount_type', 'buy_a_get_b');
    }

    public function test_retail_and_dealer_audiences_do_not_conflict_but_both_overlaps_each(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $retail = $this->postJson('/api/admin/sales-promotions', $this->discount('RETAIL20', $product->id))
            ->assertCreated()->json('data.id');
        $dealer = $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('DEALER7', $product->id, '7'), 'sales_scope' => 'dealer',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('BOTH10', $product->id, '10'), 'sales_scope' => 'both',
        ])->assertStatus(409)->assertJsonPath('existing_promotion_id', $retail)
            ->assertJsonPath('sales_channel', 'retail');
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('DEALER5', $product->id, '5'), 'sales_scope' => 'dealer',
        ])->assertStatus(409)->assertJsonPath('existing_promotion_id', $dealer)
            ->assertJsonPath('sales_channel', 'dealer');
    }

    public function test_dealer_discounts_only_conflict_when_their_tiers_overlap(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $silver = DealerTier::factory()->create();
        $gold = DealerTier::factory()->create();
        $first = $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('SILVER7', $product->id, '7'),
            'sales_scope' => 'dealer', 'dealer_tier_ids' => [$silver->id],
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('GOLD9', $product->id, '9'),
            'sales_scope' => 'dealer', 'dealer_tier_ids' => [$gold->id],
        ])->assertCreated();
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('ALL5', $product->id, '5'), 'sales_scope' => 'dealer',
        ])->assertStatus(409)->assertJsonPath('existing_promotion_id', $first);
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('SILVER3', $product->id, '3'),
            'sales_scope' => 'dealer', 'dealer_tier_ids' => [$silver->id],
        ])->assertStatus(409)->assertJsonPath('existing_promotion_id', $first);
    }

    public function test_updating_a_promotion_does_not_conflict_with_itself_but_cannot_take_another_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $own = Product::factory()->create();
        $occupied = Product::factory()->create();
        $first = $this->postJson('/api/admin/sales-promotions', $this->discount('OWN20', $own->id))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/admin/sales-promotions', $this->discount('OTHER20', $occupied->id))
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/sales-promotions/{$first}", $this->discount('OWN20', $own->id, '25'))
            ->assertOk()->assertJsonPath('data.discount_value', '25.00');
        $this->patchJson("/api/admin/sales-promotions/{$first}", [
            ...$this->discount('OWN20', $own->id), 'product_ids' => [$own->id, $occupied->id],
        ])->assertStatus(409)->assertJsonPath('existing_promotion_id', $second);
        $this->assertDatabaseMissing('sales_promotion_targets', [
            'sales_promotion_id' => $first, 'product_id' => $occupied->id,
        ]);
    }

    public function test_activation_rechecks_conflicts_and_picker_exposes_the_reason(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $inactive = $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('LATER20', $product->id), 'status' => 'inactive',
        ])->assertCreated()->json('data.id');
        $active = $this->postJson('/api/admin/sales-promotions', $this->discount('NOW10', $product->id))
            ->assertCreated()->json('data.id');

        $this->getJson('/api/admin/products?discount_availability=1')
            ->assertOk()->assertJsonPath('data.0.active_discount_promotion.id', $active);
        $this->getJson("/api/admin/products?discount_availability=1&exclude_promotion_id={$active}")
            ->assertOk()->assertJsonPath('data.0.active_discount_promotion', null);
        $this->postJson("/api/admin/sales-promotions/{$inactive}/activate")
            ->assertStatus(409)->assertJsonPath('code', 'PRODUCT_ALREADY_HAS_ACTIVE_PROMOTION');
        $this->assertDatabaseHas('sales_promotions', ['id' => $inactive, 'status' => 'inactive']);
    }

    public function test_fixed_amount_discounts_are_allowed_but_conflict_with_other_discounts_in_the_same_channel(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $retail = $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('FIXEDRETAIL', $product->id), 'discount_type' => 'fixed_amount',
            'discount_value' => '50000',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/sales-promotions', $this->discount('PERCENTRETAIL', $product->id))
            ->assertStatus(409)->assertJsonPath('existing_promotion_id', $retail);
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('GLOBALRETAIL', $product->id), 'product_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('CATEGORYRETAIL', $product->id),
            'category_ids' => [$product->product_category_id],
        ])->assertUnprocessable()->assertJsonValidationErrors('category_ids');
        $this->postJson('/api/admin/sales-promotions', [
            ...$this->discount('FIXEDDEALER', $product->id),
            'sales_scope' => 'dealer', 'discount_type' => 'fixed_amount', 'discount_value' => '50000',
        ])->assertCreated()->assertJsonPath('data.discount_type', 'fixed_amount');
    }

    /** @return array<string, mixed> */
    private function discount(string $code, int $productId, string $value = '20'): array
    {
        return ['code' => $code, 'name' => $code, 'discount_type' => 'percentage',
            'discount_value' => $value, 'sales_scope' => 'retail', 'status' => 'active',
            'product_ids' => [$productId], 'category_ids' => []];
    }
}
