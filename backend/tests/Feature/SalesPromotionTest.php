<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use App\Services\PromotionDiscountAllocator;
use App\Services\SalesPromotionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesPromotionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_filters_promotions_by_effective_status_scope_and_type(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        SalesPromotion::factory()->create(['code' => 'NOW-GOLD', 'normalized_code' => 'NOW-GOLD', 'name' => 'Gold offer',
            'sales_scope' => 'dealer', 'discount_type' => 'fixed_amount', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        SalesPromotion::factory()->create(['code' => 'LATER-GOLD', 'normalized_code' => 'LATER-GOLD', 'name' => 'Gold later',
            'sales_scope' => 'dealer', 'discount_type' => 'fixed_amount', 'starts_at' => now()->addDay()]);
        SalesPromotion::factory()->create(['code' => 'OLD-GOLD', 'normalized_code' => 'OLD-GOLD', 'name' => 'Gold old',
            'sales_scope' => 'dealer', 'discount_type' => 'fixed_amount', 'ends_at' => now()->subDay()]);

        $this->getJson('/api/admin/sales-promotions?search=Gold&status=active&sales_scope=dealer&discount_type=fixed_amount')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'NOW-GOLD');
        $this->getJson('/api/admin/sales-promotions?status=upcoming')->assertOk()->assertJsonPath('data.0.code', 'LATER-GOLD');
        $this->getJson('/api/admin/sales-promotions?status=expired')->assertOk()->assertJsonPath('data.0.code', 'OLD-GOLD');
    }

    public function test_admin_saves_percentage_and_fixed_amount_forms_with_optional_discount_cap(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $base = ['name' => 'Sales offer', 'description' => null, 'max_discount_amount' => null,
            'minimum_order_amount' => '1', 'sales_scope' => 'both', 'starts_at' => null,
            'ends_at' => null, 'total_usage_limit' => 10, 'per_buyer_usage_limit' => 1,
            'status' => 'active', 'product_ids' => [], 'category_ids' => []];

        foreach ([['PERCENT-FORM', 'percentage', '15.5'],
            ['FIXED-FORM', 'fixed_amount', '100000']] as [$code, $type, $value]) {
            $created = $this->postJson('/api/admin/sales-promotions', [...$base,
                'code' => $code, 'discount_type' => $type, 'discount_value' => $value])
                ->assertCreated()->assertJsonPath('data.max_discount_amount', null)
                ->assertJsonPath('data.discount_type', $type)->json('data');
            $this->assertDatabaseHas('sales_promotions', ['id' => $created['id'],
                'discount_type' => $type, 'max_discount_amount' => null]);
        }

        $this->assertDatabaseCount('sales_promotions', 2);
    }

    public function test_admin_promotion_creation_normalizes_code_and_keeps_clinic_vouchers_separate(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $data = ['code' => ' Welcome-10 ', 'name' => 'Welcome', 'discount_type' => 'percentage',
            'discount_value' => '10', 'sales_scope' => 'retail', 'status' => 'active',
            'minimum_order_amount' => '100.00', 'product_ids' => [], 'category_ids' => []];
        $created = $this->postJson('/api/admin/sales-promotions', $data)->assertCreated()
            ->assertJsonPath('data.normalized_code', 'WELCOME-10')->json('data');
        $this->postJson('/api/admin/sales-promotions', [...$data, 'code' => 'welcome-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('vouchers', 0);
        $this->getJson('/api/admin/sales-promotions')->assertOk()->assertJsonPath('data.0.redeemed_count', 0);
        $this->patchJson("/api/admin/sales-promotions/{$created['id']}", [...$data,
            'code' => 'WELCOME-10', 'status' => 'inactive'])->assertOk()
            ->assertJsonPath('data.status', 'inactive');
        $this->getJson("/api/admin/sales-promotions/{$created['id']}")
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/admin/sales-promotions/generate-code')->assertOk()->assertJsonStructure(['code']);
    }

    public function test_quote_targets_products_and_allocates_cents_deterministically(): void
    {
        $buyer = User::factory()->customer()->create();
        $one = ProductVariant::factory()->create();
        $two = ProductVariant::factory()->create();
        $three = ProductVariant::factory()->create();
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'fixed_amount',
            'discount_value' => '0.02', 'sales_scope' => 'retail']);
        $promotion->targets()->create(['product_id' => $one->product_id]);
        $promotion->targets()->create(['product_id' => $two->product_id]);
        $lines = [
            ['product_variant_id' => $two->id, 'product_id' => $two->product_id, 'amount' => '1.00'],
            ['product_variant_id' => $one->id, 'product_id' => $one->product_id, 'amount' => '1.00'],
            ['product_variant_id' => $three->id, 'product_id' => $three->product_id, 'amount' => '1.00'],
        ];
        $quote = app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null, '3.00', $lines);
        $this->assertSame('2.00', $quote['eligible_subtotal']);
        $this->assertSame('0.02', $quote['discount_amount']);
        $this->assertSame('2.98', $quote['grand_total_after_discount']);
        $this->assertSame('0.01', $quote['allocations'][$one->id]);
        $this->assertSame('0.01', $quote['allocations'][$two->id]);
        $this->assertArrayNotHasKey($three->id, $quote['allocations']);
        $this->assertSame([1 => '0.01', 2 => '0.00'], app(PromotionDiscountAllocator::class)->allocate([
            ['product_variant_id' => 2, 'amount' => '1.00'],
            ['product_variant_id' => 1, 'amount' => '1.00'],
        ], '0.01'));
        $this->assertSame([1 => '0.01', 2 => '0.02'], app(PromotionDiscountAllocator::class)->allocate([
            ['product_variant_id' => 1, 'amount' => '1.00'],
            ['product_variant_id' => 2, 'amount' => '2.00'],
        ], '0.03'));
    }

    public function test_category_scope_minimum_dates_and_discount_cap_are_server_enforced(): void
    {
        $buyer = User::factory()->customer()->create();
        $category = ProductCategory::factory()->create();
        $variant = ProductVariant::factory()->create();
        $variant->product->update(['product_category_id' => $category->id]);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'percentage',
            'discount_value' => '50.00', 'max_discount_amount' => '20.00',
            'minimum_order_amount' => '100.00', 'sales_scope' => 'retail']);
        $promotion->targets()->create(['product_category_id' => $category->id]);
        $lines = [['product_variant_id' => $variant->id, 'product_id' => $variant->product_id, 'amount' => '100.00']];
        $quote = app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null, '100.00', $lines);
        $this->assertSame('20.00', $quote['discount_amount']);
        try {
            app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null, '99.00', $lines);
            $this->fail('Minimum should reject the quote.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_MINIMUM_NOT_MET', $exception->getResponse()->getData(true)['code']);
        }
        $promotion->update(['starts_at' => now()->addDay()]);
        try {
            app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null, '100.00', $lines);
            $this->fail('Future promotion should reject the quote.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_NOT_STARTED', $exception->getResponse()->getData(true)['code']);
        }
        $promotion->update(['starts_at' => null, 'ends_at' => now()->subDay()]);
        try {
            app(SalesPromotionService::class)->quote($promotion->code, 'retail', $buyer->id, null, '100.00', $lines);
            $this->fail('Expired promotion should reject the quote.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_EXPIRED', $exception->getResponse()->getData(true)['code']);
        }
        $tiny = SalesPromotion::factory()->create(['discount_type' => 'percentage',
            'discount_value' => '0.01', 'sales_scope' => 'retail']);
        try {
            app(SalesPromotionService::class)->quote($tiny->code, 'retail', $buyer->id, null,
                '0.01', [['product_variant_id' => $variant->id, 'product_id' => $variant->product_id, 'amount' => '0.01']]);
            $this->fail('Zero-value discount should not consume a code.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('PROMOTION_DISCOUNT_TOO_SMALL', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_admin_rejects_invalid_discount_dates_and_usage_limits(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $base = ['code' => 'RULES', 'name' => 'Rules', 'discount_type' => 'percentage',
            'discount_value' => '10', 'sales_scope' => 'both', 'status' => 'active'];
        $this->postJson('/api/admin/sales-promotions', [...$base, 'discount_value' => '101'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_value');
        $this->postJson('/api/admin/sales-promotions', [...$base, 'discount_type' => 'fixed_amount', 'discount_value' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_value');
        $this->postJson('/api/admin/sales-promotions', [...$base,
            'starts_at' => '2026-10-02 12:00:00', 'ends_at' => '2026-10-01 12:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/admin/sales-promotions', [...$base, 'total_usage_limit' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('total_usage_limit');
        $this->assertDatabaseCount('sales_promotions', 0);
    }

    public function test_retail_checkout_uses_discounted_order_lines_and_releases_usage_on_cancel(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $variant->id, 'unit_price' => '120.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5', 'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'fixed_amount',
            'discount_value' => '20.00', 'total_usage_limit' => 1, 'per_buyer_usage_limit' => 1]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->getJson('/api/retail/cart')
            ->assertOk()->assertJsonPath('data.grand_total', '100.00');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $this->assertDatabaseCount('sales_promotion_redemptions', 0);
        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => '1 Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '100.00')
            ->assertJsonPath('data.items.0.discount_amount', '20.00')->json('data');
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $order['id'], 'status' => 'redeemed']);
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/sales-promotions/{$promotion->id}", [
            'code' => 'CHANGED-CODE', 'name' => $promotion->name,
            'discount_type' => 'fixed_amount', 'discount_value' => '20.00',
            'sales_scope' => 'both', 'status' => 'active',
        ])->assertConflict()->assertJsonPath('code', 'PROMOTION_CODE_IMMUTABLE');
        $this->putJson("/api/admin/sales-promotions/{$promotion->id}", [
            'code' => $promotion->code, 'name' => 'Updated for future orders',
            'discount_type' => 'fixed_amount', 'discount_value' => '50.00',
            'sales_scope' => 'both', 'status' => 'active',
        ])->assertOk();
        Sanctum::actingAs($buyer);
        $this->getJson("/api/retail/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.grand_total', '100.00')
            ->assertJsonPath('data.promotion.name', 'Test promotion')
            ->assertJsonPath('data.promotion.discount_value', '20.00');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer request',
        ])->assertOk();
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $order['id'], 'status' => 'released']);
        $this->artisan('sales-promotions:reconcile', ['--dry-run' => true])->assertExitCode(0);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->getJson('/api/retail/cart')
            ->assertOk()->assertJsonPath('data.grand_total', '70.00');
    }

    public function test_sales_promotion_requires_authenticated_admin_for_management(): void
    {
        $this->getJson('/api/admin/sales-promotions')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson('/api/admin/sales-promotions')->assertForbidden();

    }

    public function test_dealer_order_pays_discounted_total_from_wallet_and_rejects_retail_only_code(): void
    {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5', 'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '200.00', 'method' => 'other_manual'], $admin);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'fixed_amount',
            'discount_value' => '50.00', 'sales_scope' => 'dealer', 'per_buyer_usage_limit' => 1]);
        $retailOnly = SalesPromotion::factory()->create(['sales_scope' => 'retail']);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $this->postJson("$url/review", ['items' => $items, 'voucher_code' => $retailOnly->code])
            ->assertConflict()->assertJsonPath('code', 'VOUCHER_NOT_AVAILABLE_FOR_DEALER');
        $review = $this->postJson("$url/review", ['items' => $items])
            ->assertOk()->assertJsonPath('data.grand_total', '150.00')
            ->assertJsonPath('data.discount_total', '50.00')->json('data');
        $this->assertDatabaseCount('sales_promotion_redemptions', 0);
        $operationKey = (string) Str::uuid();
        $body = ['operation_key' => $operationKey,
            'review_fingerprint' => $review['review_fingerprint'], 'items' => $items,
            'recipient_name' => 'Recipient',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN'];
        $order = $this->postJson($url, $body)
            ->assertCreated()->assertJsonPath('data.grand_total', '150.00')
            ->assertJsonPath('data.items.0.discount_amount', '50.00')->json('data');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertDatabaseHas('payments', ['amount' => '150.00', 'payment_method' => 'dealer_wallet', 'status' => 'settled']);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '50.00']);
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $order['id'], 'status' => 'redeemed']);
        $colleague = User::factory()->customer()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $colleague->id]);
        Sanctum::actingAs($colleague);
        $this->postJson("$url/review", ['items' => $items])
            ->assertOk()->assertJsonPath('data.promotion', null);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Attempted cancellation',
        ])->assertConflict()->assertJsonPath('code', 'PAID_ORDER_REQUIRES_REFUND');
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $order['id'], 'status' => 'redeemed']);
    }

    public function test_promoted_partial_returns_and_linked_refunds_use_net_paid_value(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5', 'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'fixed_amount', 'discount_value' => '1.00']);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '3'])->assertOk();
        $this->getJson('/api/retail/cart')->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '299.00')->json('data');
        $orderId = $order['id'];
        $itemId = $order['items'][0]['id'];
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$orderId}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$orderId}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '299.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$orderId}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'preparing',
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$orderId}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'shipping',
        ])->assertOk();
        $firstReturn = $this->postJson("/api/admin/sales-orders/{$orderId}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['item_id' => $itemId, 'quantity' => '1', 'restock_quantity' => '0']],
        ])->assertCreated()->assertJsonPath('data.items.0.return_value_snapshot', '99.67')->json('data');
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'refund_method' => 'bank_transfer',
            'reason' => 'order_cancel', 'return_id' => $firstReturn['id'],
        ])->assertConflict()->assertJsonPath('code', 'REFUND_EXCEEDS_RETURN_VALUE');
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '99.67', 'refund_method' => 'bank_transfer',
            'reason' => 'order_cancel', 'return_id' => $firstReturn['id'],
        ])->assertCreated();
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $orderId, 'status' => 'redeemed']);
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '300.00', 'refund_method' => 'bank_transfer',
            'reason' => 'order_cancel',
        ])->assertConflict()->assertJsonPath('code', 'REFUND_EXCEEDS_REFUNDABLE_AMOUNT');
        $this->postJson("/api/admin/sales-orders/{$orderId}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['item_id' => $itemId, 'quantity' => '2', 'restock_quantity' => '0']],
        ])->assertCreated()->assertJsonPath('data.items.0.return_value_snapshot', '199.33');

        $percentage = SalesPromotion::factory()->create(['discount_type' => 'percentage', 'discount_value' => '10.00']);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->getJson('/api/retail/cart')->assertOk()->assertJsonPath('data.promotion.code', $percentage->code);
        $nextFingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $next = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $nextFingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '90.00')->json('data');
        $nextOrderId = $next['id'];
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$nextOrderId}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$nextOrderId}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'preparing',
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$nextOrderId}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'shipping',
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$nextOrderId}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['item_id' => $next['items'][0]['id'], 'quantity' => '1', 'restock_quantity' => '0']],
        ])->assertCreated()->assertJsonPath('data.items.0.return_value_snapshot', '90.00');
    }

    public function test_fully_discounted_dealer_order_needs_no_wallet_debit_or_payment(): void
    {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '1', 'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'fixed_amount',
            'discount_value' => '200.00', 'sales_scope' => 'dealer']);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $review = $this->postJson("$url/review", ['items' => $items])
            ->assertOk()->assertJsonPath('data.grand_total', '0.00')->json('data');
        $order = $this->postJson($url, ['operation_key' => (string) Str::uuid(),
            'review_fingerprint' => $review['review_fingerprint'], 'items' => $items,
            'recipient_name' => 'Recipient',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN'])
            ->assertCreated()->assertJsonPath('data.grand_total', '0.00')
            ->assertJsonPath('data.order_status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.outstanding_amount', '0.00')->json('data');
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'payment_status' => 'unpaid']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->assertDatabaseCount('sales_promotion_redemptions', 1);
        $this->artisan('payments:reconcile-orders', ['--dry-run' => true])
            ->expectsOutputToContain('mismatched: 0')
            ->expectsOutputToContain('legacy_paid: 0')->assertExitCode(0);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '1.00',
            'refund_method' => 'dealer_wallet', 'reason' => 'order_cancel',
        ])->assertConflict()->assertJsonPath('code', 'NOTHING_TO_REFUND');
        $this->assertDatabaseCount('refunds', 0);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer request',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertDatabaseHas('sales_promotion_redemptions', [
            'sales_order_id' => $order['id'], 'status' => 'released',
        ]);
    }

    public function test_hundred_percent_retail_promotion_keeps_zero_total_unpaid_without_settlement(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '1', 'operation_key' => (string) Str::uuid()], $admin->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'percentage',
            'discount_value' => '100.00', 'sales_scope' => 'retail']);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->getJson('/api/retail/cart')
            ->assertOk()->assertJsonPath('data.grand_total', '0.00');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');

        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => '1 Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '0.00')
            ->assertJsonPath('data.order_status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.outstanding_amount', '0.00')->json('data');
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'payment_status' => 'unpaid']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->artisan('payments:reconcile-orders', ['--dry-run' => true])
            ->expectsOutputToContain('mismatched: 0')
            ->expectsOutputToContain('legacy_paid: 0')->assertExitCode(0);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '1.00',
            'refund_method' => 'bank_transfer', 'reason' => 'order_cancel',
        ])->assertConflict()->assertJsonPath('code', 'NOTHING_TO_REFUND');
        $this->postJson("/api/admin/sales-orders/{$order['id']}/confirm", [
            'operation_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.order_status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertDatabaseCount('refunds', 0);
    }
}
