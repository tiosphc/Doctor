<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerPricingService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerPricingIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_quote_uses_authenticated_account_tier_and_reports_moq_without_ordering(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $this->dealerPrice($tier, $variant, '2300000.00', '5');
        $retail = PriceList::factory()->create();
        $retail->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '3000000.00', 'minimum_quantity' => 1]);
        Sanctum::actingAs($user);

        $url = $this->quoteUrl($account);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '2'];
        $first = $this->postJson($url, $body)->assertOk()
            ->assertJsonPath('data.unit_price', '2300000.00')
            ->assertJsonPath('data.minimum_quantity', '5.000')
            ->assertJsonPath('data.currency', 'VND')
            ->assertJsonPath('data.meets_moq', false)
            ->assertJsonPath('data.effective_tier.id', $tier->id)->json('data');
        $this->assertSame($first['price_fingerprint'], $this->postJson($url, $body)->assertOk()->json('data.price_fingerprint'));
        $this->postJson($url, [...$body, 'quantity' => '5'])->assertOk()
            ->assertJsonPath('data.meets_moq', true)->assertJsonPath('data.line_total', '11500000.00');
        $this->postJson($url, [...$body, 'quantity' => '1.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_silver_gold_and_diamond_prices_are_independent_of_warehouse(): void
    {
        [$user, $account, $silver, $variant] = $this->dealerFixture();
        $gold = DealerTier::factory()->create();
        $diamond = DealerTier::factory()->create();
        $this->dealerPrice($silver, $variant, '900000.00', '1');
        $this->dealerPrice($gold, $variant, '850000.00', '1');
        $this->dealerPrice($diamond, $variant, '800000.00', '1');
        Sanctum::actingAs($user);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '1'];
        $url = $this->quoteUrl($account);

        $this->postJson($url, $body)->assertOk()
            ->assertJsonPath('data.unit_price', '900000.00');
        $this->assertSame('900000.00', app(DealerPricingService::class)->resolve($variant, $silver, '1', false)['unit_price']);
        $account->update(['current_tier_id' => $gold->id]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '850000.00');
        $this->assertSame('850000.00', app(DealerPricingService::class)->resolve($variant, $gold, '1', false)['unit_price']);
        $account->update(['current_tier_id' => $diamond->id]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '800000.00');
        $this->assertSame('800000.00', app(DealerPricingService::class)->resolve($variant, $diamond, '1', false)['unit_price']);
    }

    public function test_dealer_cannot_choose_warehouse_in_catalog_or_quote(): void
    {
        [$user, $account] = $this->dealerFixture();
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        Warehouse::factory()->create(['status' => 'inactive']);
        Sanctum::actingAs($user);

        $this->getJson("/api/dealer/accounts/{$account->id}/warehouses")->assertNotFound();
        $this->getJson("/api/dealer/accounts/{$account->id}/products?warehouse_id={$hanoi->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_catalog_only_shows_currently_priced_dealer_variants_and_retail_stays_separate(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $this->dealerPrice($tier, $variant, '210.00', '3');
        $unpriced = ProductVariant::factory()->create(['product_id' => $variant->product_id, 'sellable_dealer' => true]);
        $retailOnly = ProductVariant::factory()->create(['sellable_dealer' => false]);
        $retail = PriceList::factory()->create();
        $retail->items()->create(['product_variant_id' => $variant->id, 'unit_price' => '300.00', 'minimum_quantity' => 1]);
        $retail->items()->create(['product_variant_id' => $unpriced->id, 'unit_price' => '400.00', 'minimum_quantity' => 1]);
        $retail->items()->create(['product_variant_id' => $retailOnly->id, 'unit_price' => '500.00', 'minimum_quantity' => 1]);
        Sanctum::actingAs($user);

        $base = "/api/dealer/accounts/{$account->id}/products";
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data.0.variants')
            ->assertJsonPath('data.0.variants.0.dealer_price.unit_price', '210.00');
        $this->getJson("$base/{$variant->product->slug}")->assertOk()->assertJsonCount(1, 'data.variants');
        $this->getJson("$base/{$retailOnly->product->slug}")->assertNotFound();
        $this->postJson($this->quoteUrl($account), ['product_variant_id' => $unpriced->id, 'quantity' => '1'])
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $this->getJson("/api/products/{$variant->product->slug}")->assertOk()
            ->assertJsonPath('data.retail_price.unit_price', '300.00')
            ->assertDontSee('210.00');
        $this->getJson('/api/products')->assertOk()->assertDontSee('210.00');
    }

    public function test_catalog_search_matches_product_name_sku_variant_and_specification_values(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $variant->product->update(['name' => 'Juvederm Ultra']);
        $variant->update(['sku' => 'JUV-U3-3ML', 'variant_name' => 'Ultra 3',
            'specifications' => ['size' => '3ml']]);
        $this->dealerPrice($tier, $variant, '1250000.00', '5');
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}/products";

        foreach (['juve', 'u3-3', 'ultra 3', '3ml'] as $term) {
            $this->getJson($base.'?'.http_build_query(['search' => $term]))
                ->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.variants.0.sku', 'JUV-U3-3ML');
        }
        $this->getJson($base.'?search=unknown-variant')->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_override_expiry_cancellation_tier_change_and_price_edit_apply_on_next_read(): void
    {
        [$user, $account, $silver, $variant] = $this->dealerFixture();
        $gold = DealerTier::factory()->create();
        $this->dealerPrice($silver, $variant, '250.00', '10');
        $goldItem = $this->dealerPrice($gold, $variant, '230.00', '5');
        Sanctum::actingAs($user);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '1'];
        $url = $this->quoteUrl($account);
        $baseFingerprint = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '250.00')->json('data.price_fingerprint');

        $override = DealerTierOverride::factory()->create([
            'dealer_account_id' => $account->id, 'tier_id' => $gold->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
        ]);
        $goldQuote = $this->postJson($url, $body)->assertOk()
            ->assertJsonPath('data.unit_price', '230.00')
            ->assertJsonPath('data.pricing_source', 'manual_override')->json('data');
        $this->assertNotSame($baseFingerprint, $goldQuote['price_fingerprint']);
        $goldItem->update(['unit_price' => '225.00']);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '225.00');
        $override->update(['status' => DealerTierOverride::STATUS_CANCELLED]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '250.00');
        $override->update(['status' => DealerTierOverride::STATUS_ACTIVE]);
        $this->travelTo(now()->addHours(2));
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '250.00');
        $account->update(['current_tier_id' => $gold->id]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '225.00');
    }

    public function test_missing_inactive_and_ambiguous_prices_fail_closed(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        Sanctum::actingAs($user);
        $url = $this->quoteUrl($account);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '1'];
        $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $item = $this->dealerPrice($tier, $variant, '90.00', '1', ['effective_from' => now()->addDay()]);
        $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $item->update(['effective_from' => null]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '90.00');
        $item->update(['status' => 'inactive']);
        $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $item->update(['status' => 'active']);
        $item->update(['unit_price' => '0.00']);
        $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $item->update(['unit_price' => '90.00']);
        $second = $this->dealerPrice($tier, $variant, '80.00', '1');
        $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_AMBIGUOUS');
        $second->priceList->update(['priority' => 2]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '80.00');
        $second->priceList->update(['status' => 'inactive']);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.unit_price', '90.00');
        $tier->update(['status' => 'inactive']);
        $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_INACTIVE');
    }

    public function test_quantity_precision_and_client_price_or_tier_spoofing_are_rejected(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $this->dealerPrice($tier, $variant, '99.00', '1');
        Sanctum::actingAs($user);
        $url = $this->quoteUrl($account);
        foreach (['0', '-1', '1.5', '1.0001'] as $quantity) {
            $this->postJson($url, ['product_variant_id' => $variant->id, 'quantity' => $quantity])
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->postJson($url, ['product_variant_id' => $variant->id, 'quantity' => '1',
            'tier_id' => 999, 'unit_price' => '1.00', 'currency' => 'USD', 'price_list_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors(['tier_id', 'unit_price', 'currency', 'price_list_id']);
        $variant->unit->update(['decimal_precision' => 2]);
        $this->postJson($url, ['product_variant_id' => $variant->id, 'quantity' => '0.25'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_dealer_context_and_sellability_are_enforced_for_every_read(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $this->dealerPrice($tier, $variant, '100.00', '1');
        $base = "/api/dealer/accounts/{$account->id}/products";
        $quote = $this->quoteUrl($account);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '1'];
        $this->getJson($base)->assertUnauthorized();
        $this->postJson($quote, $body)->assertUnauthorized();
        foreach ([User::factory()->customer()->create(), User::factory()->doctor()->create(), User::factory()->receptionist()->create()] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson($base)->assertNotFound();
            $this->getJson("$base/{$variant->product->slug}")->assertNotFound();
            $this->postJson($quote, $body)->assertNotFound();
        }
        $doctor = User::factory()->doctor()->create();
        DealerAccountUser::factory()->create(['user_id' => $doctor->id, 'dealer_account_id' => $account->id]);
        Sanctum::actingAs($doctor);
        $this->getJson($base)->assertNotFound();
        $this->postJson($quote, $body)->assertNotFound();
        Sanctum::actingAs($user);
        $variant->update(['sellable_dealer' => false]);
        $this->postJson($quote, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_SKU_NOT_SELLABLE');
        $this->getJson($base)->assertJsonPath('meta.total', 0);
        $variant->update(['sellable_dealer' => true]);
        DealerAccountUser::query()->where('dealer_account_id', $account->id)->update(['status' => 'inactive']);
        $this->postJson($quote, $body)->assertNotFound();
        DealerAccountUser::query()->where('dealer_account_id', $account->id)->update(['status' => 'active']);
        $account->update(['status' => DealerAccount::STATUS_SUSPENDED]);
        $this->getJson($base)->assertNotFound();
        $this->postJson($quote, $body)->assertNotFound();
    }

    public function test_unassigned_tier_is_explicit_and_admin_cannot_configure_zero_dealer_price(): void
    {
        [$user, $account, , $variant] = $this->dealerFixture();
        $account->update(['current_tier_id' => null]);
        Sanctum::actingAs($user);
        $this->getJson("/api/dealer/accounts/{$account->id}/products")
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_NOT_ASSIGNED');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson("/api/admin/products/{$variant->product_id}/pricing", [
            'sellable_retail' => false, 'sellable_dealer' => true,
            'dealer_rules' => [['tier_id' => DealerTier::factory()->create()->id,
                'sku' => $variant->sku, 'min_quantity' => 1, 'unit_price' => '0']],
        ])->assertUnprocessable()->assertJsonValidationErrors('dealer_rules.0.unit_price');
    }

    public function test_product_sku_list_dates_and_currency_gate_dealer_visibility(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $item = $this->dealerPrice($tier, $variant, '75.00', '1');
        Sanctum::actingAs($user);
        $quote = $this->quoteUrl($account);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '1'];
        $catalog = "/api/dealer/accounts/{$account->id}/products";
        $item->priceList->update(['effective_from' => now()->addDay()]);
        $this->postJson($quote, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $this->getJson($catalog)->assertOk()->assertJsonPath('meta.total', 0);
        $item->priceList->update(['effective_from' => null, 'currency' => 'USD']);
        $this->postJson($quote, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_PRICE_NOT_FOUND');
        $item->priceList->update(['currency' => 'VND']);
        $variant->update(['status' => 'inactive']);
        $this->postJson($quote, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_SKU_NOT_SELLABLE');
        $this->getJson($catalog)->assertOk()->assertJsonPath('meta.total', 0);
        $variant->update(['status' => 'active']);
        $variant->product->update(['status' => 'inactive']);
        $this->postJson($quote, $body)->assertStatus(409)->assertJsonPath('code', 'DEALER_SKU_NOT_SELLABLE');
        $this->getJson($catalog)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_each_account_uses_its_own_effective_tier_and_cannot_access_another_account(): void
    {
        [$user, $account, $silver, $variant] = $this->dealerFixture();
        $gold = DealerTier::factory()->create();
        $other = DealerAccount::factory()->create(['current_tier_id' => $gold->id]);
        $this->dealerPrice($silver, $variant, '250.00', '1');
        $this->dealerPrice($gold, $variant, '200.00', '1');
        Sanctum::actingAs($user);
        $body = ['product_variant_id' => $variant->id, 'quantity' => '1'];
        $this->postJson($this->quoteUrl($account), $body)->assertOk()->assertJsonPath('data.unit_price', '250.00');
        $this->postJson($this->quoteUrl($other), $body)->assertNotFound();
        DealerAccountUser::factory()->create(['user_id' => $user->id, 'dealer_account_id' => $other->id]);
        $this->postJson($this->quoteUrl($other), $body)->assertOk()->assertJsonPath('data.unit_price', '200.00');
        $this->postJson($this->quoteUrl($account), $body)->assertOk()->assertJsonPath('data.unit_price', '250.00');
    }

    public function test_retail_cart_and_checkout_review_use_retail_price_even_for_dealer_member(): void
    {
        [$user, $account, $tier, $variant] = $this->dealerFixture();
        $variant->update(['track_inventory' => true]);
        $this->dealerPrice($tier, $variant, '100.00', '1');
        $retail = PriceList::factory()->create();
        $retail->items()->create(['product_variant_id' => $variant->id,
            'unit_price' => '120.00', 'minimum_quantity' => 1]);
        $warehouse = Warehouse::query()->where('is_default_sales', true)->firstOrFail();
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '5', 'operation_key' => (string) Str::uuid(),
        ], User::factory()->admin()->create()->id);
        Sanctum::actingAs($user);
        $this->postJson($this->quoteUrl($account), ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertOk()->assertJsonPath('data.unit_price', '100.00');
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertOk()->assertJsonPath('data.items.0.retail_price.unit_price', '120.00')
            ->assertDontSee('100.00');
        $this->getJson('/api/retail/checkout/review')->assertOk()
            ->assertJsonPath('data.grand_total', '120.00')->assertDontSee('100.00');
    }

    /** @return array{User, DealerAccount, DealerTier, ProductVariant} */
    private function dealerFixture(): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['user_id' => $user->id, 'dealer_account_id' => $account->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true,
            'unit_id' => Unit::factory()->create(['decimal_precision' => 0])->id]);
        Warehouse::factory()->create(['is_default_sales' => true]);

        return [$user, $account, $tier, $variant];
    }

    /** @param array<string, mixed> $attributes */
    private function dealerPrice(DealerTier $tier, ProductVariant $variant, string $price, string $minimum, array $attributes = []): PriceListItem
    {
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);

        return $list->items()->create(['product_variant_id' => $variant->id,
            'unit_price' => $price, 'minimum_quantity' => $minimum, ...$attributes]);
    }

    private function quoteUrl(DealerAccount $account): string
    {
        return "/api/dealer/accounts/{$account->id}/pricing/quote";
    }
}
