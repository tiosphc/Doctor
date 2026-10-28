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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DealerBladeCatalogProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{User, DealerAccount, DealerTier, ProductVariant} */
    private function fixture(string $price = '210.00'): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => $price, 'minimum_quantity' => 3]);

        return [$user, $account, $tier, $variant];
    }

    public function test_catalog_profile_and_promotions_require_active_dealer_membership(): void
    {
        foreach (['products.index', 'profile.index', 'promotions.index'] as $name) {
            $this->get(route('dealer.web.'.$name))->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->customer()->create());
        foreach (['products.index', 'profile.index', 'promotions.index'] as $name) {
            $this->get(route('dealer.web.'.$name))->assertForbidden();
        }

        [$user, $account, , $variant] = $this->fixture();
        $this->actingAs($user);
        $this->get(route('dealer.web.products.index'))->assertOk();
        $this->get(route('dealer.web.products.show', $variant->product->slug))->assertOk();
        $this->get(route('dealer.web.profile.index'))->assertOk();
        $this->get(route('dealer.web.promotions.index'))->assertOk();
        $account->update(['status' => 'suspended']);
        $this->get(route('dealer.web.products.index'))->assertForbidden();
        $this->get(route('dealer.web.profile.index'))->assertForbidden();
    }

    public function test_catalog_and_detail_show_only_effective_dealer_tier_price_and_moq(): void
    {
        [$user, $account, $tier, $variant] = $this->fixture();
        $variant->product->update(['name' => 'Dealer Catalog Product']);
        $unpriced = ProductVariant::factory()->create(['product_id' => $variant->product_id,
            'sellable_dealer' => true]);
        $retail = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $retail->id,
            'product_variant_id' => $variant->id, 'unit_price' => '999.00']);
        PriceListItem::factory()->create(['price_list_id' => $retail->id,
            'product_variant_id' => $unpriced->id, 'unit_price' => '888.00']);
        $this->actingAs($user);

        $index = $this->get(route('dealer.web.products.index', ['search' => $variant->sku]));
        $index->assertOk()->assertSee('Dealer Catalog Product')->assertSee($variant->sku)
            ->assertSee($tier->name)
            ->assertDontSee($unpriced->sku);
        $this->assertSame('210.00', $index->viewData('products')[0]['variants'][0]['dealer_price']['unit_price']);
        $detail = $this->get(route('dealer.web.products.show', $variant->product->slug));
        $detail->assertOk()->assertSee($variant->sku)->assertSee('3.000')
            ->assertDontSee($unpriced->sku)
            ->assertSee(route('dealer.web.quick.create'));
        $this->assertSame('210.00', $detail->viewData('product')['variants'][0]['dealer_price']['unit_price']);
        $this->get(route('dealer.web.products.show', 'missing-product'))->assertNotFound();
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_catalog_and_profile_use_primary_account_without_client_account_switching(): void
    {
        [$user, $primary, , $variant] = $this->fixture('210.00');
        $otherTier = DealerTier::factory()->create();
        $other = DealerAccount::factory()->create(['current_tier_id' => $otherTier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $other->id, 'user_id' => $user->id]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $otherTier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '50.00']);
        $this->actingAs($user);

        $catalog = $this->get(route('dealer.web.products.index', ['dealer_account_id' => $other->id]));
        $catalog->assertOk()->assertSee($primary->legal_name);
        $this->assertSame('210.00', $catalog->viewData('products')[0]['variants'][0]['dealer_price']['unit_price']);
        $this->get(route('dealer.web.profile.index'))->assertOk()
            ->assertSee($primary->legal_name)->assertDontSee($other->legal_name);
    }

    public function test_catalog_excludes_inactive_products_and_variants_without_dealer_price(): void
    {
        [$user, , , $variant] = $this->fixture();
        $inactiveProduct = ProductVariant::factory()->create(['sellable_dealer' => true]);
        $inactiveProduct->product->update(['status' => 'inactive']);
        $inactiveVariant = ProductVariant::factory()->create(['product_id' => $variant->product_id,
            'sellable_dealer' => true, 'status' => 'inactive']);
        $this->actingAs($user);

        $catalog = $this->get(route('dealer.web.products.index', ['per_page' => 1]));
        $catalog->assertOk()->assertSee($variant->sku)->assertDontSee($inactiveVariant->sku)
            ->assertDontSee($inactiveProduct->sku);
        $this->get(route('dealer.web.products.show', $inactiveProduct->product->slug))->assertNotFound();
    }

    public function test_promotion_page_shows_only_effective_dealer_eligible_offers(): void
    {
        [$user, , $tier] = $this->fixture();
        SalesPromotion::factory()->create(['name' => 'Dealer active offer', 'sales_scope' => 'dealer']);
        SalesPromotion::factory()->create(['name' => 'Retail only offer', 'sales_scope' => 'retail']);
        SalesPromotion::factory()->create(['name' => 'Dealer expired offer', 'sales_scope' => 'dealer',
            'ends_at' => now()->subDay()]);
        $otherTier = DealerTier::factory()->create();
        $otherTierOffer = SalesPromotion::factory()->create(['name' => 'Other tier offer', 'sales_scope' => 'dealer']);
        $otherTierOffer->dealerTiers()->attach($otherTier->id);
        $this->actingAs($user);

        $this->get(route('dealer.web.promotions.index'))->assertOk()
            ->assertSee('Dealer active offer')->assertDontSee('Retail only offer')
            ->assertDontSee('Dealer expired offer')->assertDontSee('Other tier offer');
        $this->assertNotSame($tier->id, $otherTier->id);
    }

    public function test_profile_navigation_and_promotion_page_keep_supported_read_only_links(): void
    {
        [$user, $account, , $variant] = $this->fixture();
        $this->actingAs($user);
        $response = $this->get(route('dealer.web.profile.index'));
        $response->assertOk()->assertSee($account->legal_name)
            ->assertSee(route('dealer.web.products.index'))->assertSee(route('dealer.web.addresses.index'))
            ->assertSee(route('dealer.web.wallet.index'))->assertSee(route('dealer.web.orders.index'));
        $this->get(route('dealer.web.promotions.index'))->assertOk()
            ->assertSee(route('dealer.web.quick.create'))->assertDontSee('voucher_code');
        $this->get(route('dealer.web.products.show', $variant->product->slug))->assertOk();
    }
}
