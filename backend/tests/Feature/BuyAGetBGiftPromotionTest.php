<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use App\Services\SalesPromotionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuyAGetBGiftPromotionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_saves_gift_form_with_null_discount_cap_and_zero_discount_value(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $buy = ProductVariant::factory()->create(['track_inventory' => true,
            'sellable_retail' => true, 'sellable_dealer' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true]);
        $body = ['code' => 'GIFT-FORM', 'name' => 'Gift form', 'description' => null,
            'discount_type' => 'buy_a_get_b', 'discount_value' => '0', 'max_discount_amount' => null,
            'minimum_order_amount' => '1', 'sales_scope' => 'both', 'starts_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'total_usage_limit' => 10,
            'per_buyer_usage_limit' => 1, 'status' => 'active', 'dealer_tier_ids' => [],
            'gift_rule' => ['buy_product_id' => $buy->product_id, 'buy_variant_id' => null,
                'minimum_buy_quantity' => '1', 'gift_product_id' => $gift->product_id,
                'gift_variant_id' => $gift->id, 'gift_quantity' => '1', 'repeat_per_multiple' => false]];

        $created = $this->postJson('/api/admin/sales-promotions', $body)->assertCreated()
            ->assertJsonPath('data.discount_type', 'buy_a_get_b')
            ->assertJsonPath('data.max_discount_amount', null)->json('data');
        $this->assertDatabaseHas('sales_promotions', ['id' => $created['id'],
            'discount_value' => '0.00', 'max_discount_amount' => null]);
        $this->assertDatabaseHas('sales_promotion_gift_rules', ['sales_promotion_id' => $created['id'],
            'buy_variant_id' => null, 'gift_variant_id' => $gift->id]);
        $this->postJson('/api/admin/sales-promotions', [...$body, 'code' => 'GIFT-CAP',
            'max_discount_amount' => '100'])->assertUnprocessable()
            ->assertJsonValidationErrors('max_discount_amount');
    }

    public function test_admin_configures_gift_rule_and_rejects_unmarked_gift_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $body = ['code' => 'BUY10GIFT1', 'name' => 'Buy ten get one',
            'discount_type' => 'buy_a_get_b', 'sales_scope' => 'retail', 'status' => 'active',
            'gift_rule' => ['buy_product_id' => $buy->product_id, 'buy_variant_id' => $buy->id,
                'minimum_buy_quantity' => '10', 'gift_product_id' => $gift->product_id,
                'gift_variant_id' => $gift->id, 'gift_quantity' => '1', 'repeat_per_multiple' => true]];
        $this->postJson('/api/admin/sales-promotions', $body)
            ->assertUnprocessable()->assertJsonValidationErrors('gift_rule.gift_product_id');
        $gift->product->update(['can_be_gift' => true]);
        $created = $this->postJson('/api/admin/sales-promotions', $body)->assertCreated()
            ->assertJsonPath('data.discount_type', 'buy_a_get_b')
            ->assertJsonPath('data.gift_rule.gift_variant_id', $gift->id)->json('data');
        $this->assertDatabaseHas('sales_promotions', ['id' => $created['id'], 'discount_value' => '0.00']);
        $this->assertDatabaseHas('sales_promotion_gift_rules', ['sales_promotion_id' => $created['id'],
            'gift_variant_id' => $gift->id]);
        $this->postJson("/api/admin/sales-promotions/{$created['id']}/deactivate")->assertOk();
        $gift->update(['status' => 'inactive']);
        $this->postJson("/api/admin/sales-promotions/{$created['id']}/activate")
            ->assertUnprocessable()->assertJsonValidationErrors('gift_rule');
    }

    public function test_admin_can_save_and_reactivate_buy_a_get_same_unmarked_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $variant = ProductVariant::factory()->create(['track_inventory' => true,
            'sellable_retail' => true, 'sellable_dealer' => true]);
        $this->assertFalse($variant->product->can_be_gift);
        $body = ['code' => 'BUY10GET1SAME', 'name' => 'Buy ten get one same SKU',
            'discount_type' => 'buy_a_get_b', 'sales_scope' => 'both', 'status' => 'active',
            'gift_rule' => ['buy_product_id' => $variant->product_id, 'buy_variant_id' => $variant->id,
                'minimum_buy_quantity' => '10', 'gift_product_id' => $variant->product_id,
                'gift_variant_id' => $variant->id, 'gift_quantity' => '1', 'repeat_per_multiple' => true]];
        $id = $this->postJson('/api/admin/sales-promotions', $body)->assertCreated()
            ->assertJsonPath('data.gift_rule.gift_variant_id', $variant->id)->json('data.id');
        $differentGift = ProductVariant::factory()->create(['track_inventory' => true]);
        $differentGift->product->update(['can_be_gift' => true]);
        $this->patchJson("/api/admin/sales-promotions/{$id}", [
            ...$body,
            'gift_rule' => [...$body['gift_rule'], 'gift_product_id' => $differentGift->product_id,
                'gift_variant_id' => $differentGift->id],
        ])->assertOk()->assertJsonPath('data.gift_rule.gift_product_id', $differentGift->product_id);
        $this->patchJson("/api/admin/sales-promotions/{$id}", $body)->assertOk()
            ->assertJsonPath('data.gift_rule.gift_product_id', $variant->product_id)
            ->assertJsonPath('data.gift_rule.gift_variant_id', $variant->id);
        $this->postJson("/api/admin/sales-promotions/{$id}/deactivate")->assertOk();
        $this->postJson("/api/admin/sales-promotions/{$id}/activate")->assertOk();
    }

    public function test_same_product_gift_quotes_once_multiples_and_other_variant_without_gift_flag(): void
    {
        $buyer = User::factory()->customer()->create();
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['product_id' => $buy->product_id, 'track_inventory' => true]);
        $promotion = $this->promotion($buy, $buy, true);
        foreach (['9' => '0.000', '10' => '1.000', '20' => '2.000', '25' => '2.000', '30' => '3.000'] as $quantity => $expected) {
            $quote = app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id,
                null, '100.00', [['product_variant_id' => $buy->id, 'product_id' => $buy->product_id,
                    'amount' => '100.00', 'quantity' => $quantity]]);
            $this->assertSame($expected, $quote['gift_quantity']);
        }
        $promotion->giftRule->update(['repeat_per_multiple' => false, 'gift_variant_id' => $gift->id]);
        $quote = app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id,
            null, '100.00', [['product_variant_id' => $buy->id, 'product_id' => $buy->product_id,
                'amount' => '100.00', 'quantity' => '25']]);
        $this->assertSame('1.000', $quote['gift_quantity']);
        $this->assertSame($gift->id, $quote['gift_variant_id']);
        $promotion->update(['sales_scope' => 'dealer']);
        try {
            app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id,
                null, '100.00', [['product_variant_id' => $buy->id, 'product_id' => $buy->product_id,
                    'amount' => '100.00', 'quantity' => '10']]);
            $this->fail('Retail should not receive a Dealer promotion.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_NOT_APPLICABLE_TO_CHANNEL',
                $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_same_sku_gift_requires_combined_stock_and_recalculates_after_cart_quantity_changes(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_retail' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = $this->promotion($variant, $variant, true);
        $lines = [['product_variant_id' => $variant->id, 'product_id' => $variant->product_id,
            'amount' => '1000.00', 'quantity' => '10']];
        try {
            app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id,
                null, '1000.00', $lines, false, $warehouse->id);
            $this->fail('Ten units must not satisfy ten paid plus one gift.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_GIFT_OUT_OF_STOCK',
                $exception->getResponse()->getData(true)['code']);
        }
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '10'])
            ->assertOk()->assertJsonPath('data.gift_item', null)
            ->assertJsonPath('data.gift_unavailable_reason', 'PROMOTION_GIFT_OUT_OF_STOCK');
        $itemId = $this->getJson('/api/retail/cart')->json('data.items.0.id');
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '9'])
            ->assertOk()->assertJsonPath('data.gift_item', null);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '20',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '10'])
            ->assertOk()->assertJsonPath('data.gift_item.quantity', '1.000');
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '20'])
            ->assertOk()->assertJsonPath('data.gift_item.quantity', '2.000');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        app(InventoryService::class)->adjust(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '-9',
            'operation_key' => (string) Str::uuid(), 'reason_code' => 'DEMO',
            'reason_detail' => 'Stock changed before checkout'], $admin->id);
        $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(),
            'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertConflict()->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '9'])
            ->assertOk()->assertJsonPath('data.gift_item', null);
    }

    public function test_exact_repeat_formula_counts_only_paid_quantity(): void
    {
        $buyer = User::factory()->customer()->create();
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true]);
        $promotion = $this->promotion($buy, $gift, true);

        foreach (['9' => '0.000', '10' => '1.000', '19' => '1.000', '20' => '2.000', '30' => '3.000'] as $quantity => $expected) {
            $quote = app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null,
                '100.00', [['product_variant_id' => $buy->id, 'product_id' => $buy->product_id,
                    'amount' => '100.00', 'quantity' => $quantity]]);
            $this->assertSame($expected, $quote['gift_quantity']);
            $this->assertSame('0.00', $quote['discount_amount']);
        }
        $promotion->giftRule->update(['repeat_per_multiple' => false]);
        $quote = app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null,
            '100.00', [['product_variant_id' => $buy->id, 'product_id' => $buy->product_id,
                'amount' => '100.00', 'quantity' => '100']]);
        $this->assertSame('1.000', $quote['gift_quantity']);
    }

    public function test_retail_checkout_reserves_real_gift_and_requires_clawback_on_paid_return(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $buy->id, 'unit_price' => '100.00']);
        foreach ([$buy->id => '10', $gift->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId, 'quantity' => $quantity,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $promotion = $this->promotion($buy, $gift, false);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $gift->id, 'quantity' => '1'])
            ->assertConflict()->assertJsonPath('code', 'GIFT_ONLY_PRODUCT_NOT_PURCHASABLE');
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $buy->id, 'quantity' => '10'])
            ->assertOk()->assertJsonPath('data.voucher_code', null)
            ->assertJsonPath('data.promotion.code', $promotion->code)
            ->assertJsonPath('data.gift_item.quantity', '1.000');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '1000.00')->json('data');
        $giftItem = collect($order['items'])->firstWhere('is_gift', true);
        $paidItem = collect($order['items'])->firstWhere('is_gift', false);
        $this->assertNotNull($giftItem);
        $this->assertSame('0.00', $giftItem['unit_price']);
        $this->assertSame('0.00', $giftItem['line_total']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_item_id' => $giftItem['id'],
            'original_quantity' => '1.000']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_item_id' => $paidItem['id'],
            'original_quantity' => '10.000']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$order['id']}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'preparing',
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$order['id']}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'shipping',
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$order['id']}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer return',
            'items' => [['item_id' => $paidItem['id'], 'quantity' => '5', 'restock_quantity' => '5']],
        ])->assertConflict()->assertJsonPath('code', 'GIFT_RETURN_REQUIRED');
        $this->postJson("/api/admin/sales-orders/{$order['id']}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer return',
            'items' => [['item_id' => $paidItem['id'], 'quantity' => '5', 'restock_quantity' => '5'],
                ['item_id' => $giftItem['id'], 'quantity' => '1', 'restock_quantity' => '1']],
        ])->assertCreated();
        $this->assertDatabaseHas('sales_return_items', [
            'sales_order_item_id' => $giftItem['id'], 'quantity' => '1.000',
            'return_value_snapshot' => '0.00',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $gift->id, 'movement_type' => 'SALES_RETURN',
            'quantity' => '1.000',
        ]);
        $this->artisan('sales-promotions:reconcile', ['--dry-run' => true])->assertExitCode(0);
    }

    public function test_retail_cart_applies_qualified_gift_automatically_and_respects_manual_voucher(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $buy->id, 'unit_price' => '100.00']);
        foreach ([$buy->id => '20', $gift->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId, 'quantity' => $quantity,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $giftPromotion = $this->promotion($buy, $gift, false);
        $discountPromotion = SalesPromotion::factory()->create(['sales_scope' => 'retail',
            'minimum_order_amount' => '0.00', 'status' => 'inactive']);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $buy->id, 'quantity' => '9'])
            ->assertOk()->assertJsonPath('data.gift_item', null);
        $itemId = $this->getJson('/api/retail/cart')->json('data.items.0.id');
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '10'])
            ->assertOk()->assertJsonPath('data.promotion.code', $giftPromotion->code)
            ->assertJsonPath('data.gift_item.sku', $gift->sku);
        $this->putJson('/api/retail/cart/voucher', ['code' => $discountPromotion->code])
            ->assertConflict()->assertJsonPath('code', 'VOUCHER_NOT_FOUND');
        $this->putJson('/api/retail/cart/voucher', ['code' => null])
            ->assertOk()->assertJsonPath('data.promotion.code', $giftPromotion->code)
            ->assertJsonPath('data.gift_item.sku', $gift->sku);
        app(InventoryService::class)->adjust(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $gift->id, 'quantity' => '-1',
            'operation_key' => (string) Str::uuid(), 'reason_code' => 'DEMO',
            'reason_detail' => 'Gift stock unavailable'], $admin->id);
        $this->getJson('/api/retail/cart')->assertOk()->assertJsonPath('data.gift_item', null)
            ->assertJsonPath('data.can_checkout', true);
    }

    public function test_retail_cart_prefers_available_gift_over_competing_discount_and_falls_back_when_gift_runs_out(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $buy->id, 'unit_price' => '100.00']);
        foreach ([$buy->id => '20', $gift->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId, 'quantity' => $quantity,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $discount = SalesPromotion::factory()->create(['sales_scope' => 'retail',
            'discount_type' => 'fixed_amount', 'discount_value' => '50.00',
            'minimum_order_amount' => '0.00']);
        $discount->targets()->create(['product_id' => $buy->product_id]);
        $giftPromotion = $this->promotion($buy, $gift, false);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $buy->id, 'quantity' => '10'])
            ->assertOk()->assertJsonPath('data.promotion.code', $giftPromotion->code)
            ->assertJsonPath('data.gift_item.sku', $gift->sku)
            ->assertJsonPath('data.grand_total', '1000.00');

        app(InventoryService::class)->adjust(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $gift->id, 'quantity' => '-1',
            'operation_key' => (string) Str::uuid(), 'reason_code' => 'DEMO',
            'reason_detail' => 'Gift stock unavailable'], $admin->id);
        $this->getJson('/api/retail/cart')->assertOk()
            ->assertJsonPath('data.promotion.code', $discount->code)
            ->assertJsonPath('data.gift_item', null)
            ->assertJsonPath('data.grand_total', '950.00');
    }

    public function test_same_sku_paid_and_gift_reserve_cumulative_stock_once(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '3',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = $this->promotion($variant, $variant, false);
        $promotion->giftRule->update(['minimum_buy_quantity' => '2.000']);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '2'])->assertOk();
        $this->getJson('/api/retail/cart')->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '200.00')->json('data');
        $this->assertCount(2, $order['items']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '3.000', 'reserved_quantity' => '3.000']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$order['id']}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'preparing',
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$order['id']}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'shipping',
        ])->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '0.000', 'reserved_quantity' => '0.000']);
    }

    public function test_cancelled_retail_gift_order_releases_both_reservations_and_usage(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $buy = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $buy->id, 'unit_price' => '100.00']);
        foreach ([$buy->id => '10', $gift->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive([
                'warehouse_id' => $warehouse->id, 'product_variant_id' => $variantId,
                'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
            ], $admin->id);
        }
        $promotion = $this->promotion($buy, $gift, false);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', [
            'product_variant_id' => $buy->id, 'quantity' => '10',
        ])->assertOk();
        $this->getJson('/api/retail/cart')->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->json('data');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer cancelled',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $buy->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $gift->id,
            'on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000',
        ]);
        $this->assertDatabaseHas('sales_promotion_redemptions', [
            'sales_order_id' => $order['id'], 'status' => 'released',
        ]);
        $this->getJson("/api/admin/sales-promotions/{$promotion->id}")
            ->assertOk()->assertJsonPath('data.gift_units_granted', '0');
    }

    public function test_dealer_tier_target_uses_effective_tier_id(): void
    {
        $buyer = User::factory()->customer()->create();
        $eligibleTier = DealerTier::factory()->create();
        $otherTier = DealerTier::factory()->create();
        $buy = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_dealer' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true]);
        $promotion = $this->promotion($buy, $gift, false);
        $promotion->update(['sales_scope' => 'dealer']);
        $promotion->dealerTiers()->attach($eligibleTier->id);
        $lines = [['product_variant_id' => $buy->id, 'product_id' => $buy->product_id,
            'amount' => '100.00', 'quantity' => '10']];
        $quote = app(SalesPromotionService::class)->quote($promotion->code, 'dealer', $buyer->id,
            1, '100.00', $lines, false, null, $eligibleTier->id);
        $this->assertSame('1.000', $quote['gift_quantity']);
        try {
            app(SalesPromotionService::class)->quote($promotion->code, 'dealer', $buyer->id,
                1, '100.00', $lines, false, null, $otherTier->id);
            $this->fail('Other tier should not receive the Gift.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_NOT_APPLICABLE_TO_TIER',
                $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_dealer_quick_order_settles_only_paid_lines_while_reserving_unpriced_gift(): void
    {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $buy = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $gift = ProductVariant::factory()->create(['track_inventory' => true]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $buy->id, 'unit_price' => '100.00', 'minimum_quantity' => '10']);
        foreach ([$buy->id => '10', $gift->id => '1'] as $variantId => $quantity) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId, 'quantity' => $quantity,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '1000.00', 'method' => 'other_manual',
        ], $admin);
        $promotion = $this->promotion($buy, $gift, false);
        $promotion->update(['sales_scope' => 'dealer']);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $buy->id, 'quantity' => '10']];
        $shipping = $this->dealerShipping();
        $review = $this->postJson("$url/review", ['items' => $items, ...$shipping])
            ->assertOk()->assertJsonPath('data.grand_total', '1000.00')
            ->assertJsonPath('data.gift_item.quantity', '1.000')->json('data');
        $order = $this->postJson($url, [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => $items, ...$shipping,
        ])->assertCreated()->assertJsonPath('data.grand_total', '1000.00')->json('data');
        $giftItem = collect($order['items'])->firstWhere('is_gift', true);
        $this->assertNotNull($giftItem);
        $this->assertSame('0.00', $giftItem['line_total']);
        $this->assertDatabaseHas('payments', ['amount' => '1000.00',
            'payment_method' => 'dealer_wallet', 'status' => 'settled']);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id,
            'balance' => '0.00']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_item_id' => $giftItem['id'],
            'original_quantity' => '1.000']);
    }

    public function test_dealer_quick_order_can_give_the_same_unmarked_sku(): void
    {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '10']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '11',
            'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '1000.00', 'method' => 'other_manual',
        ], $admin);
        $promotion = $this->promotion($variant, $variant, false);
        $promotion->update(['sales_scope' => 'dealer']);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '10']];
        $shipping = $this->dealerShipping();
        $review = $this->postJson("$url/review", ['items' => $items, ...$shipping])
            ->assertOk()->assertJsonPath('data.gift_item.quantity', '1.000')->json('data');
        $order = $this->postJson($url, [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => $items, ...$shipping,
        ])->assertCreated()->assertJsonPath('data.grand_total', '1000.00')->json('data');
        $this->assertCount(2, $order['items']);
        $this->assertSame($variant->id, $order['items'][0]['product_variant_id']);
        $this->assertSame($variant->id, $order['items'][1]['product_variant_id']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '11.000',
            'reserved_quantity' => '11.000']);
        $this->assertDatabaseHas('payments', ['amount' => '1000.00',
            'payment_method' => 'dealer_wallet', 'status' => 'settled']);
        $this->assertDatabaseHas('payment_allocations', ['sales_order_id' => $order['id'],
            'allocated_amount' => '1000.00']);
    }

    /** @return array<string, string> */
    private function dealerShipping(): array
    {
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();

        return ['recipient_name' => 'Recipient', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_province_code' => '79',
            'shipping_ward_code' => $ward->code];
    }

    private function promotion(ProductVariant $buy, ProductVariant $gift, bool $repeat): SalesPromotion
    {
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'buy_a_get_b',
            'discount_value' => '0.00', 'max_discount_amount' => null, 'sales_scope' => 'retail']);
        $promotion->giftRule()->create(['buy_product_id' => $buy->product_id,
            'buy_variant_id' => $buy->id, 'minimum_buy_quantity' => '10.000',
            'gift_product_id' => $gift->product_id, 'gift_variant_id' => $gift->id,
            'gift_quantity' => '1.000', 'repeat_per_multiple' => $repeat]);

        return $promotion;
    }
}
