<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\RetailCheckoutService;
use App\Services\SalesPromotionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeSalesPromotionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function discount(string $code, int $productId, array $overrides = []): array
    {
        return [...['code' => $code, 'name' => 'Ưu đãi Product', 'discount_type' => 'percentage',
            'discount_value' => '10', 'sales_scope' => 'retail', 'status' => 'active',
            'product_ids' => [$productId]], ...$overrides];
    }

    public function test_admin_routes_require_admin_and_invalid_ids_return_not_found(): void
    {
        $this->withoutVite();
        $this->get('/admin/sales-promotions')->assertRedirect('/login');
        $this->get('/admin/sales-promotions/options?type=products')->assertRedirect('/login');
        $this->post('/admin/sales-promotions', [])->assertRedirect('/login');

        $this->actingAs(User::factory()->customer()->create());
        $this->get('/admin/sales-promotions')->assertForbidden();
        $this->post('/admin/sales-promotions', [])->assertForbidden();
        $this->get('/admin/sales-promotions/options?type=products')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/sales-promotions/999999')->assertNotFound();
        $this->get('/admin/sales-promotions/999999/edit')->assertNotFound();
    }

    public function test_list_filters_effective_status_type_scope_and_escapes_names(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $current = SalesPromotion::factory()->create(['code' => 'CURRENT', 'normalized_code' => 'CURRENT',
            'name' => '<script>alert(1)</script>', 'sales_scope' => 'retail', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay()]);
        SalesPromotion::factory()->create(['code' => 'FUTURE', 'normalized_code' => 'FUTURE',
            'sales_scope' => 'retail', 'starts_at' => now()->addDay()]);
        SalesPromotion::factory()->create(['code' => 'OLD', 'normalized_code' => 'OLD',
            'sales_scope' => 'retail', 'ends_at' => now()->subDay()]);
        SalesPromotion::factory()->create(['code' => 'OFF', 'normalized_code' => 'OFF', 'status' => 'inactive']);

        $this->get('/admin/sales-promotions?status=active&sales_scope=retail&discount_type=percentage')
            ->assertOk()->assertSee($current->code)->assertDontSee('FUTURE')->assertDontSee('OLD')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/admin/sales-promotions?status=upcoming')->assertOk()->assertSee('FUTURE');
        $this->get('/admin/sales-promotions?status=expired')->assertOk()->assertSee('OLD');
        $this->get('/admin/sales-promotions?status=inactive')->assertOk()->assertSee('OFF');
    }

    public function test_admin_creates_percentage_promotion_and_retail_pricing_reads_it(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $this->actingAs($admin);

        $this->get('/admin/sales-promotions/create')->assertOk()->assertSee('Tạo khuyến mãi');
        $this->get('/admin/sales-promotions/options?type=products&search='.$product->product_code)
            ->assertOk()->assertJsonFragment(['id' => $product->id]);
        $this->post('/admin/sales-promotions', $this->discount('WEB-PROMO-10', $product->id))
            ->assertRedirect();
        $promotion = SalesPromotion::query()->where('normalized_code', 'WEB-PROMO-10')->firstOrFail();
        $this->get('/admin/sales-promotions/'.$promotion->id)->assertOk()->assertSee($product->product_code);
        $this->assertDatabaseHas('sales_promotion_targets', ['sales_promotion_id' => $promotion->id,
            'product_id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'SALES_PROMOTION', 'target_id' => $promotion->id,
            'actor_id' => $admin->id]);
        $this->assertSame('90.00', app(SalesPromotionService::class)->discountedUnitPrice('100.00', $promotion));
    }

    public function test_discount_conflict_invalid_percentage_and_expired_promotion_are_handled(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $existing = SalesPromotion::factory()->create(['sales_scope' => 'retail']);
        $existing->targets()->create(['product_id' => $product->id]);
        $expired = SalesPromotion::factory()->create(['sales_scope' => 'retail', 'ends_at' => now()->subDay()]);
        $expired->targets()->create(['product_id' => $other->id]);

        $this->from('/admin/sales-promotions/create')->post('/admin/sales-promotions',
            $this->discount('WEB-CONFLICT', $product->id))->assertSessionHasErrors('operation');
        $this->from('/admin/sales-promotions/create')->post('/admin/sales-promotions',
            $this->discount('WEB-INVALID', $product->id, ['discount_value' => '101']))
            ->assertSessionHasErrors('discount_value');
        $this->from('/admin/sales-promotions/create')->post('/admin/sales-promotions',
            $this->discount('WEB-DATES', $other->id, ['starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at' => now()->subDay()->toDateTimeString()]))->assertSessionHasErrors('ends_at');
        $this->post('/admin/sales-promotions', $this->discount('WEB-AFTER-EXPIRY', $other->id))->assertRedirect();
        $this->assertDatabaseMissing('sales_promotions', ['code' => 'WEB-CONFLICT']);
        $this->assertDatabaseHas('sales_promotions', ['code' => 'WEB-AFTER-EXPIRY']);
    }

    public function test_deactivation_and_conflicting_reactivation_preserve_status_and_audit(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $product = Product::factory()->create();
        $promotion = SalesPromotion::factory()->create(['sales_scope' => 'retail']);
        $promotion->targets()->create(['product_id' => $product->id]);
        $url = '/admin/sales-promotions/'.$promotion->id;

        $this->post($url.'/deactivate')->assertRedirect();
        $this->assertSame('inactive', $promotion->fresh()->status);
        $this->post('/admin/sales-promotions', $this->discount('WEB-NEW-ACTIVE', $product->id))->assertRedirect();
        $this->from($url)->post($url.'/activate')->assertSessionHasErrors('operation');
        $this->assertSame('inactive', $promotion->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['module' => 'SALES_PROMOTION',
            'target_id' => $promotion->id, 'action' => 'DEACTIVATE', 'actor_id' => $admin->id]);
    }

    public function test_gift_configuration_uses_existing_validation_without_inventory_writes(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true]);
        $body = ['code' => 'WEB-GIFT', 'name' => 'Tặng B', 'discount_type' => 'buy_a_get_b',
            'discount_value' => '0', 'sales_scope' => 'retail', 'status' => 'active',
            'gift_rule' => ['buy_product_id' => $buy->product_id, 'buy_variant_id' => $buy->id,
                'minimum_buy_quantity' => '2', 'gift_product_id' => $gift->product_id,
                'gift_variant_id' => $gift->id, 'gift_quantity' => '1', 'repeat_per_multiple' => '1']];

        $this->post('/admin/sales-promotions', $body)->assertRedirect();
        $promotion = SalesPromotion::query()->where('normalized_code', 'WEB-GIFT')->firstOrFail();
        $this->assertDatabaseHas('sales_promotion_gift_rules', ['sales_promotion_id' => $promotion->id,
            'gift_variant_id' => $gift->id, 'minimum_buy_quantity' => '2.000']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->from('/admin/sales-promotions/create')->post('/admin/sales-promotions',
            [...$body, 'code' => 'WEB-BAD-GIFT', 'gift_rule' => [...$body['gift_rule'],
                'gift_variant_id' => $buy->id]])->assertSessionHasErrors('gift_rule.gift_variant_id');
        $this->get('/admin/sales-promotions/'.$promotion->id)->assertOk()->assertSee($gift->sku);
    }

    public function test_blade_created_product_discount_is_visible_in_retail_checkout_preview(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id,
            'unit_price' => '120000.00',
        ]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5',
            'operation_key' => (string) Str::uuid(),
        ], $admin->id);

        $this->actingAs($admin)->post('/admin/sales-promotions',
            $this->discount('WEB-RETAIL-10', $variant->product_id))->assertRedirect();
        $this->actingAs($buyer)->post(route('retail.web.cart.items.add'), [
            'product_variant_id' => $variant->id,
            'quantity' => '1',
        ])->assertRedirect(route('retail.web.cart.index'));

        $review = app(RetailCheckoutService::class)->review($buyer->id);
        $this->assertSame('108000.00', $review['grand_total']);
        $this->assertDatabaseCount('sales_promotion_redemptions', 0);
        $this->assertDatabaseCount('sales_orders', 0);
    }
}
