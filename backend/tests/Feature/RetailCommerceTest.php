<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetailCommerceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, Warehouse, ProductVariant, PriceListItem} */
    private function catalog(string $stock = '10'): array
    {
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $price = PriceListItem::factory()->create([
            'price_list_id' => $list->id, 'product_variant_id' => $variant->id, 'unit_price' => '120000.00',
        ]);
        if ($stock !== '0') {
            app(InventoryService::class)->receive([
                'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
                'quantity' => $stock, 'operation_key' => (string) Str::uuid(),
            ], User::factory()->admin()->create()->id);
        }

        return [$buyer, $warehouse, $variant, $price];
    }

    /** @return array<string, string> */
    private function checkoutBody(string $fingerprint, ?string $key = null): array
    {
        return [
            'checkout_operation_key' => $key ?? (string) Str::uuid(),
            'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Separate Recipient', 'recipient_phone' => '0900000000',
            'recipient_email' => 'recipient@example.com',
            'shipping_address_line1' => '123 Street', 'shipping_city' => 'HCM',
            'shipping_district' => 'District 1', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'payment_method' => 'cod',
        ];
    }

    public function test_retail_price_does_not_change_between_zero_and_three_percent_warehouses(): void
    {
        [, $hcm, $variant, $price] = $this->catalog();
        $price->update(['unit_price' => '1000000.00']);
        $hcm->update(['dealer_price_adjustment_percent' => '0.00']);
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        $items = [['sku' => $variant->sku, 'quantity' => '1']];

        $this->assertSame('1000000.00', app(SalesOrderService::class)
            ->previewRetail(['warehouse_id' => $hcm->id, 'items' => $items])['items'][0]['unit_price']);
        $this->assertSame('1000000.00', app(SalesOrderService::class)
            ->previewRetail(['warehouse_id' => $hanoi->id, 'items' => $items])['items'][0]['unit_price']);
    }

    public function test_cart_lifecycle_preview_and_no_reservation(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->getJson('/api/retail/cart')->assertOk()->assertJsonPath('data.item_count', 0);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertOk()->assertJsonPath('data.items.0.retail_price.unit_price', '120000.00');
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '2'])
            ->assertOk()->assertJsonPath('data.items.0.quantity', '3.000')
            ->assertJsonPath('data.grand_total', '360000.00');
        $itemId = $this->getJson('/api/retail/cart')->json('data.items.0.id');
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '4'])
            ->assertOk()->assertJsonPath('data.items.0.quantity', '4.000');
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'reserved_quantity' => '0.000']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->deleteJson("/api/retail/cart/items/{$itemId}")->assertOk()->assertJsonPath('data.item_count', 0);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->deleteJson('/api/retail/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    }

    public function test_invalid_cart_line_remains_readable_and_cannot_be_checked_out(): void
    {
        [$buyer, , $variant, $price] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1.5'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $variant->update(['track_inventory' => false]);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertConflict()->assertJsonPath('code', 'RETAIL_SKU_NOT_ORDERABLE');
        $variant->update(['track_inventory' => true]);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $price->update(['status' => 'inactive']);
        $this->getJson('/api/retail/cart')->assertOk()->assertJsonPath('data.item_count', 1)
            ->assertJsonPath('data.items.0.errors.0', 'PRICE_NOT_FOUND');
        $this->getJson('/api/retail/checkout/review')->assertOk()->assertJsonPath('data.can_checkout', false);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertConflict()->assertJsonPath('code', 'PRICE_NOT_FOUND');
    }

    public function test_cart_rejects_fractional_zero_and_negative_quantities_without_writes(): void
    {
        [$buyer, , $variant] = $this->catalog();
        Sanctum::actingAs($buyer);

        foreach (['1.001', '1.5', '0', '-1'] as $quantity) {
            $this->postJson('/api/retail/cart/items', [
                'product_variant_id' => $variant->id, 'quantity' => $quantity,
            ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }

        $this->assertDatabaseCount('cart_items', 0);
        $this->postJson('/api/retail/cart/items', [
            'product_variant_id' => $variant->id, 'quantity' => '1',
        ])->assertOk();
        $itemId = $this->getJson('/api/retail/cart')->json('data.items.0.id');
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '1.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseHas('cart_items', ['id' => $itemId, 'quantity' => '1.000']);
    }

    public function test_review_resolves_default_and_never_creates_order_or_reservation(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->getJson('/api/retail/checkout/review')->assertConflict()->assertJsonPath('code', 'CART_EMPTY');
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '2'])->assertOk();
        $warehouse->update(['is_default_sales' => false]);
        $this->getJson('/api/retail/checkout/review')->assertConflict()->assertJsonPath('code', 'CHECKOUT_WAREHOUSE_NOT_CONFIGURED');
        $warehouse->update(['is_default_sales' => true]);
        $this->getJson('/api/retail/checkout/review')->assertOk()
            ->assertJsonPath('data.grand_total', '240000.00')
            ->assertJsonPath('data.warehouse.id', $warehouse->id);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_checkout_creates_pending_order_once_and_scopes_my_orders(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '2'])->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $body = $this->checkoutBody($fingerprint);
        $first = $this->postJson('/api/retail/checkout', $body)->assertOk()
            ->assertJsonPath('data.sales_channel', 'retail')->assertJsonPath('data.order_source', 'cart')
            ->assertJsonPath('data.order_status', 'pending')->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.recipient_name', 'Separate Recipient')
            ->assertJsonPath('data.grand_total', '240000.00');
        $orderId = $first->json('data.id');
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id]);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'converted', 'converted_sales_order_id' => $orderId]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'reserved_quantity' => '0.000']);
        $this->postJson('/api/retail/checkout', $body)->assertOk()->assertJsonPath('data.id', $orderId);
        $this->postJson('/api/retail/checkout', [...$body, 'recipient_name' => 'Changed'])
            ->assertConflict()->assertJsonPath('code', 'CHECKOUT_OPERATION_CONFLICT');
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->getJson('/api/retail/orders')->assertOk()->assertJsonPath('data.0.id', $orderId);
        $this->getJson("/api/retail/orders/{$orderId}")->assertOk()->assertJsonPath('data.items.0.product_name', $variant->product->name);
        $this->getJson('/api/retail/cart')->assertOk()->assertJsonPath('data.item_count', 0);
        $this->assertDatabaseCount('carts', 2);
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("/api/retail/orders/{$orderId}")->assertNotFound();
        $this->getJson('/api/retail/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_checkout_detects_cart_price_and_default_warehouse_changes(): void
    {
        [$buyer, $warehouse, $variant, $price] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $old = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $itemId = $this->getJson('/api/retail/cart')->json('data.items.0.id');
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '2'])->assertOk();
        $this->postJson('/api/retail/checkout', $this->checkoutBody($old))
            ->assertConflict()->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $current = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $price->update(['unit_price' => '130000.00']);
        $this->postJson('/api/retail/checkout', $this->checkoutBody($current))
            ->assertConflict()->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $current = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $warehouse->update(['is_default_sales' => false]);
        Warehouse::factory()->create(['is_default_sales' => true]);
        $this->postJson('/api/retail/checkout', $this->checkoutBody($current))
            ->assertConflict()->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'active']);
    }

    public function test_stock_shortage_rolls_back_all_items_and_preserves_cart(): void
    {
        [$buyer, $warehouse, $variant] = $this->catalog('1');
        Sanctum::actingAs($buyer);
        $other = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['product_variant_id' => $other->id]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $other->id,
            'quantity' => '2', 'operation_key' => (string) Str::uuid(),
        ], User::factory()->admin()->create()->id);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $other->id, 'quantity' => '2'])->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        app(InventoryService::class)->adjust([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $other->id,
            'quantity' => '-1', 'operation_key' => (string) Str::uuid(),
            'reason_code' => 'OTHER', 'reason_detail' => 'Other use',
        ], User::factory()->admin()->create()->id);
        $this->postJson('/api/retail/checkout', $this->checkoutBody($fingerprint))
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('message', "SKU {$other->sku} chỉ còn 1 sản phẩm, nhưng bạn đang đặt 2. Vui lòng giảm số lượng.");
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'active']);
    }

    public function test_authentication_client_authority_and_cart_item_ownership(): void
    {
        [$buyer, , $variant] = $this->catalog();
        $this->getJson('/api/retail/cart')->assertUnauthorized();
        $this->getJson('/api/retail/orders')->assertUnauthorized();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $itemId = $this->getJson('/api/retail/cart')->json('data.items.0.id');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $this->postJson('/api/retail/checkout', [...$this->checkoutBody($fingerprint), 'buyer_user_id' => $buyer->id + 1])
            ->assertUnprocessable()->assertJsonValidationErrors('buyer_user_id');
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->patchJson("/api/retail/cart/items/{$itemId}", ['quantity' => '2'])->assertNotFound();
        $this->deleteJson("/api/retail/cart/items/{$itemId}")->assertNotFound();
    }

    public function test_my_order_uses_historical_snapshots_without_admin_fields(): void
    {
        [$buyer, , $variant, $price] = $this->catalog();
        Sanctum::actingAs($buyer);
        $originalName = $variant->product->name;
        $originalSku = $variant->sku;
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $id = $this->postJson('/api/retail/checkout', $this->checkoutBody($fingerprint))->assertOk()->json('data.id');
        $variant->product->update(['name' => 'New Product Name']);
        $variant->update(['variant_name' => 'New Variant Name']);
        $price->update(['unit_price' => '130000.00']);
        $response = $this->getJson("/api/retail/orders/{$id}")->assertOk()
            ->assertJsonPath('data.items.0.product_name', $originalName)
            ->assertJsonPath('data.items.0.sku', $originalSku)
            ->assertJsonPath('data.items.0.unit_price', '120000.00');
        $this->assertArrayNotHasKey('buyer_user_id', $response->json('data'));
        $this->assertArrayNotHasKey('created_by', $response->json('data'));
        $this->assertArrayNotHasKey('reservations', $response->json('data'));
    }

    public function test_invalidated_product_blocks_checkout_but_preserves_cart(): void
    {
        [$buyer, , $variant] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $variant->product->update(['status' => 'inactive']);
        $review = $this->getJson('/api/retail/checkout/review')->assertOk()
            ->assertJsonPath('data.items.0.errors.0', 'PRODUCT_INACTIVE');
        $this->postJson('/api/retail/checkout', $this->checkoutBody($review->json('data.review_fingerprint')))
            ->assertConflict()->assertJsonPath('code', 'CHECKOUT_ITEM_INVALID');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseHas('carts', ['user_id' => $buyer->id, 'status' => 'active']);
    }

    public function test_admin_retail_lifecycle_reserves_ships_without_inventing_cod_payment(): void
    {
        [$buyer, $warehouse, $variant, $price] = $this->catalog('10');
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '3'])->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $id = $this->postJson('/api/retail/checkout', $this->checkoutBody($fingerprint))->assertOk()
            ->assertJsonPath('data.order_status', 'pending')->assertJsonPath('data.payment_method', 'cod')->json('data.id');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000']);
        $price->update(['unit_price' => '130000.00']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'shipping',
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.order_status', 'confirmed')
            ->assertJsonPath('data.items.0.unit_price_snapshot', '120000.00');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '3.000']);
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'preparing',
        ])->assertOk()->assertJsonPath('data.order_status', 'preparing');
        $key = (string) Str::uuid();
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => $key, 'target' => 'shipping',
        ])->assertOk()->assertJsonPath('data.payment_status', 'unpaid');
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => $key, 'target' => 'shipping',
        ])->assertOk()->assertJsonPath('data.order_status', 'shipping');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'on_hand_quantity' => '7.000', 'reserved_quantity' => '0.000']);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Too late',
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->postJson("/api/admin/sales-orders/{$id}/warehouse", [
            'operation_key' => (string) Str::uuid(), 'warehouse_id' => Warehouse::factory()->create()->id,
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'delivered',
        ])->assertOk()->assertJsonPath('data.order_status', 'delivered')
            ->assertJsonPath('data.fulfillment_status', 'fulfilled')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertDatabaseCount('payments', 0);
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'completed',
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '360000', 'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.summary.payment_status', 'paid');
        $this->getJson("/api/admin/sales-orders/{$id}")
            ->assertOk()->assertJsonPath('data.order_status', 'completed');
        $this->getJson("/api/admin/sales-orders/{$id}")->assertOk()->assertJsonCount(7, 'data.histories');
    }

    public function test_paid_retail_cart_order_completes_only_after_delivery(): void
    {
        [$buyer, , $variant] = $this->catalog();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $id = $this->postJson('/api/retail/checkout', [
            ...$this->checkoutBody($fingerprint), 'payment_method' => 'bank_transfer',
        ])->assertOk()->json('data.id');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        foreach (['preparing', 'shipping'] as $target) {
            $this->postJson("/api/admin/sales-orders/{$id}/advance", [
                'operation_key' => (string) Str::uuid(), 'target' => $target,
            ])->assertOk();
        }
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '120000', 'payment_method' => 'bank_transfer',
        ])->assertCreated()->assertJsonPath('data.summary.payment_status', 'paid');
        $this->getJson("/api/admin/sales-orders/{$id}")
            ->assertOk()->assertJsonPath('data.order_status', 'shipping')
            ->assertJsonPath('data.fulfillment_status', 'fulfilled');
        $this->postJson("/api/admin/sales-orders/{$id}/advance", [
            'operation_key' => (string) Str::uuid(), 'target' => 'delivered',
        ])->assertOk()->assertJsonPath('data.order_status', 'completed')
            ->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_admin_can_change_warehouse_and_cancellation_releases_reservation(): void
    {
        [$buyer, $old, $variant] = $this->catalog('5');
        $new = Warehouse::factory()->create(['is_default_sales' => false]);
        app(InventoryService::class)->receive(['warehouse_id' => $new->id,
            'product_variant_id' => $variant->id, 'quantity' => '4',
            'operation_key' => (string) Str::uuid()], User::factory()->admin()->create()->id);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '3'])->assertOk();
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $id = $this->postJson('/api/retail/checkout', $this->checkoutBody($fingerprint))->assertOk()->json('data.id');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$id}/warehouse", [
            'operation_key' => (string) Str::uuid(), 'warehouse_id' => $new->id,
        ])->assertOk()->assertJsonPath('data.warehouse_id', $new->id);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $old->id, 'reserved_quantity' => '0.000']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $new->id, 'reserved_quantity' => '3.000']);
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer request',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $new->id,
            'on_hand_quantity' => '4.000', 'reserved_quantity' => '0.000']);
    }

    public function test_automatic_promotion_discount_is_snapshotted_and_restored_when_order_cancelled(): void
    {
        [$buyer, , $variant] = $this->catalog();
        $promotion = SalesPromotion::factory()->create(['discount_value' => '10.00']);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])->assertOk();
        $this->getJson('/api/retail/cart')
            ->assertOk()->assertJsonPath('data.discount_total', '12000.00')
            ->assertJsonPath('data.grand_total', '108000.00');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $id = $this->postJson('/api/retail/checkout', $this->checkoutBody($fingerprint))->assertOk()
            ->assertJsonPath('data.discount_total', '12000.00')->json('data.id');
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $id, 'status' => 'redeemed']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer request',
        ])->assertOk();
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $id, 'status' => 'released']);
    }
}
