<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesPromotion;
use App\Models\SalesVoucher;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\RetailCheckoutService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetailBladeCommerceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{User, Warehouse, ProductVariant, PriceListItem} */
    private function catalog(): array
    {
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $price = PriceListItem::factory()->create([
            'price_list_id' => $list->id, 'product_variant_id' => $variant->id, 'unit_price' => '120000.00',
        ]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '10', 'operation_key' => (string) Str::uuid(),
        ], User::factory()->admin()->create()->id);

        return [$buyer, $warehouse, $variant, $price];
    }

    /** @return array<string, string> */
    private function checkoutBody(string $fingerprint, string $method = 'cod', ?string $key = null): array
    {
        return [
            'checkout_operation_key' => $key ?? (string) Str::uuid(),
            'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Separate Recipient', 'recipient_phone' => '0900000000',
            'recipient_email' => 'recipient@example.com',
            'shipping_address_line1' => '123 Street', 'shipping_city' => 'HCM',
            'shipping_district' => 'District 1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => $method,
        ];
    }

    public function test_public_shop_and_customer_gate(): void
    {
        [$buyer, , $variant] = $this->catalog();
        $hidden = ProductVariant::factory()->create(['track_inventory' => false]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id, 'product_variant_id' => $hidden->id]);

        $this->get(route('retail.web.shop.index'))->assertOk()->assertSee($variant->sku)->assertDontSee($hidden->sku);
        $this->get(route('retail.web.shop.show', $variant->product->slug))->assertOk()->assertSee($variant->sku);
        $this->get(route('retail.web.shop.show', $hidden->product->slug))->assertNotFound();
        $this->get(route('retail.web.cart.index'))->assertRedirect(route('login'));
        $this->get(route('retail.web.checkout.create'))->assertRedirect(route('login'));
        $this->get(route('retail.web.orders.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->create())->get(route('retail.web.cart.index'))->assertForbidden();
        $this->actingAs($buyer)->get(route('retail.web.cart.index'))->assertOk();
    }

    public function test_login_returns_customer_to_local_retail_page(): void
    {
        $buyer = User::factory()->customer()->create(['password' => bcrypt('secret-password')]);
        $this->get(route('login', ['return_to' => '/shop']))->assertOk()->assertSee('name="return_to"', false);
        $this->post(route('blade.login'), [
            'email' => $buyer->email, 'password' => 'secret-password',
            'return_to' => 'https://example.com/shop',
        ])->assertRedirect('/shop');
    }

    public function test_cart_forms_persist_validate_ownership_and_clear_without_reservation(): void
    {
        [$buyer, , $variant] = $this->catalog();
        $this->actingAs($buyer);
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertRedirect(route('retail.web.cart.index'));
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '2'])
            ->assertRedirect(route('retail.web.cart.index'));
        $item = CartItem::query()->sole();
        $this->assertSame('3.000', $item->quantity);
        $this->get(route('retail.web.cart.index'))->assertOk()->assertSee($variant->sku)->assertSee('360.000');
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1'])
            ->assertSessionHasErrors('unit_price');
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1.5'])
            ->assertSessionHasErrors('quantity');
        $this->patch(route('retail.web.cart.items.update', $item->id), ['quantity' => '4'])
            ->assertRedirect(route('retail.web.cart.index'));
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => '4.000']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->actingAs(User::factory()->customer()->create());
        $this->patch(route('retail.web.cart.items.update', $item->id), ['quantity' => '2'])->assertNotFound();
        $this->delete(route('retail.web.cart.items.remove', $item->id))->assertNotFound();
        $this->actingAs($buyer);
        $this->delete(route('retail.web.cart.items.remove', $item->id))->assertRedirect(route('retail.web.cart.index'));
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1']);
        $this->delete(route('retail.web.cart.clear'))->assertRedirect(route('retail.web.cart.index'));
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_cart_and_checkout_review_include_multiple_retail_variants(): void
    {
        [$buyer, $warehouse, $first, $firstPrice] = $this->catalog();
        $second = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create([
            'price_list_id' => $firstPrice->price_list_id,
            'product_variant_id' => $second->id,
            'unit_price' => '50000.00',
        ]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $second->id,
            'quantity' => '3', 'operation_key' => (string) Str::uuid(),
        ], User::factory()->admin()->create()->id);
        $this->actingAs($buyer);
        foreach ([$first, $second] as $variant) {
            $this->post(route('retail.web.cart.items.add'), [
                'product_variant_id' => $variant->id, 'quantity' => '1',
            ])->assertRedirect(route('retail.web.cart.index'));
        }
        $this->get(route('retail.web.cart.index'))->assertOk()->assertSee($first->sku)->assertSee($second->sku);
        $review = app(RetailCheckoutService::class)->review($buyer->id);
        $this->assertCount(2, $review['items']);
        $this->assertSame('170000.00', $review['grand_total']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_checkout_creates_one_pending_unpaid_cod_order_and_replay_is_safe(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog();
        $this->actingAs($buyer);
        $this->get(route('retail.web.checkout.create'))->assertRedirect(route('retail.web.cart.index'));
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '2']);
        $this->get(route('retail.web.checkout.create'))->assertOk()->assertSee($variant->product->name);
        $this->assertDatabaseCount('sales_orders', 0);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $body = $this->checkoutBody($fingerprint);
        $this->post(route('retail.web.checkout.store'), $body)->assertRedirect();
        $order = SalesOrder::query()->sole();
        $this->assertSame('retail', $order->sales_channel);
        $this->assertSame('cart', $order->order_source);
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('240000.00', $order->grand_total);
        $this->assertSame($warehouse->id, $order->warehouse_id);
        $this->assertSame($buyer->id, $order->buyer_user_id);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'converted', 'converted_sales_order_id' => $order->id]);
        $this->post(route('retail.web.checkout.store'), $body)->assertRedirect(route('retail.web.orders.show', $order->id));
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->get(route('retail.web.cart.index'))->assertOk()->assertViewHas('cart', fn (array $cart): bool => $cart['item_count'] === 0);
    }

    public function test_bank_transfer_stays_unpaid_and_stale_review_cannot_write_order(): void
    {
        [$buyer, , $variant, $price] = $this->catalog();
        $this->actingAs($buyer);
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1']);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $price->update(['unit_price' => '130000.00']);
        $this->post(route('retail.web.checkout.store'), $this->checkoutBody($fingerprint, 'bank_transfer'))
            ->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('sales_orders', 0);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $this->post(route('retail.web.checkout.store'), $this->checkoutBody($fingerprint, 'bank_transfer'))
            ->assertRedirect();
        $order = SalesOrder::query()->sole();
        $this->assertSame('bank_transfer', $order->payment_method);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('130000.00', $order->grand_total);
    }

    public function test_my_orders_use_snapshots_and_exclude_another_customer(): void
    {
        [$buyer, , $variant, $price] = $this->catalog();
        $this->actingAs($buyer);
        $originalName = $variant->product->name;
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1']);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $this->post(route('retail.web.checkout.store'), $this->checkoutBody($fingerprint))->assertRedirect();
        $order = SalesOrder::query()->sole();
        $variant->product->update(['name' => 'Changed product']);
        $price->update(['unit_price' => '999999.00']);
        $this->patch(route('retail.web.profile.update'), ['name' => 'Updated Buyer', 'phone' => '0911111111'])
            ->assertRedirect(route('retail.web.profile.show'));
        $this->assertDatabaseHas('sales_orders', [
            'id' => $order->id, 'recipient_name' => 'Separate Recipient',
            'recipient_phone' => '0900000000', 'grand_total' => '120000.00',
        ]);
        $this->get(route('retail.web.orders.index'))->assertOk()->assertSee($order->order_code);
        $this->get(route('retail.web.orders.index', ['order_status' => 'cancelled']))->assertOk()->assertDontSee($order->order_code);
        $this->get(route('retail.web.orders.show', $order->id))->assertOk()->assertSee($originalName)
            ->assertDontSee('Changed product')->assertSee('120.000');
        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('retail.web.orders.index'))->assertOk()->assertDontSee($order->order_code);
        $this->get(route('retail.web.orders.show', $order->id))->assertNotFound();
        $this->get(route('retail.web.orders.show', 999999))->assertNotFound();
    }

    public function test_checkout_rejects_invalid_fields_and_cart_survives_unavailable_stock(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog();
        $this->actingAs($buyer);
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => 999999, 'quantity' => '1'])
            ->assertSessionHasErrors('product_variant_id');
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1']);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $this->post(route('retail.web.checkout.store'), [
            ...$this->checkoutBody($fingerprint), 'payment_method' => 'paid',
            'shipping_address_line1' => '', 'grand_total' => '1',
        ])->assertSessionHasErrors(['payment_method', 'shipping_address_line1', 'grand_total']);
        $this->assertDatabaseCount('sales_orders', 0);
        app(InventoryService::class)->adjust([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '-10', 'operation_key' => (string) Str::uuid(),
            'reason_code' => 'OTHER', 'reason_detail' => 'Other use',
        ], User::factory()->admin()->create()->id);
        $this->get(route('retail.web.checkout.create'))->assertOk()->assertDontSee('name="checkout_operation_key"', false);
        $this->post(route('retail.web.checkout.store'), $this->checkoutBody($fingerprint))
            ->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'active']);
    }

    public function test_web_voucher_and_promotion_preview_do_not_redeem_before_submit(): void
    {
        [$buyer, , $variant] = $this->catalog();
        $admin = User::factory()->admin()->create();
        $promotion = SalesPromotion::factory()->create(['discount_value' => '10.00']);
        $voucher = SalesVoucher::create([
            'code' => 'JUNIE10', 'normalized_code' => 'JUNIE10', 'name' => 'Welcome',
            'sales_scope' => 'retail', 'discount_type' => 'percentage',
            'discount_value' => '10.00', 'minimum_order_amount' => '0.00',
            'status' => 'active', 'created_by_user_id' => $admin->id,
        ]);
        $this->actingAs($buyer);
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '1']);
        $this->put(route('retail.web.cart.voucher'), ['code' => 'invalid'])->assertSessionHasErrors('cart');
        $this->put(route('retail.web.cart.voucher'), ['code' => 'junie10'])
            ->assertRedirect(route('retail.web.cart.index'));
        $this->get(route('retail.web.cart.index'))->assertOk()->assertSee('JUNIE10')->assertSee($promotion->name);
        $this->get(route('retail.web.checkout.create'))->assertOk()->assertSee('JUNIE10');
        $this->assertDatabaseCount('sales_voucher_redemptions', 0);
        $this->assertDatabaseCount('sales_promotion_redemptions', 0);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $this->post(route('retail.web.checkout.store'), $this->checkoutBody($fingerprint))->assertRedirect();
        $order = SalesOrder::query()->sole();
        $this->assertSame('JUNIE10', $order->sales_voucher_code_snapshot);
        $this->assertSame($voucher->id, $order->sales_voucher_id);
        $this->assertLessThan(120000, (float) $order->grand_total);
        $this->assertDatabaseHas('sales_voucher_redemptions', ['sales_order_id' => $order->id, 'status' => 'redeemed']);
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $order->id, 'status' => 'redeemed']);
    }

    public function test_web_gift_preview_and_checkout_keep_existing_gift_rule(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog();
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '1', 'operation_key' => (string) Str::uuid(),
        ], User::factory()->admin()->create()->id);
        $promotion = SalesPromotion::factory()->create([
            'discount_type' => 'buy_a_get_b', 'discount_value' => '0.00',
            'max_discount_amount' => null, 'sales_scope' => 'retail',
        ]);
        $promotion->giftRule()->create([
            'buy_product_id' => $variant->product_id, 'buy_variant_id' => $variant->id,
            'minimum_buy_quantity' => '10.000', 'gift_product_id' => $variant->product_id,
            'gift_variant_id' => $variant->id, 'gift_quantity' => '1.000',
            'repeat_per_multiple' => false,
        ]);
        $this->actingAs($buyer);
        $this->post(route('retail.web.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => '10'])
            ->assertRedirect(route('retail.web.cart.index'));
        $this->get(route('retail.web.cart.index'))->assertOk()->assertSee('Quà tặng');
        $this->get(route('retail.web.checkout.create'))->assertOk()->assertSee('Quà tặng');
        $this->assertDatabaseCount('sales_promotion_redemptions', 0);
        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $this->post(route('retail.web.checkout.store'), $this->checkoutBody($fingerprint))->assertRedirect();
        $order = SalesOrder::query()->sole();
        $this->assertSame(2, $order->items()->count());
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order->id, 'is_gift' => 1]);
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $order->id, 'status' => 'redeemed']);
    }
}
