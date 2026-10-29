<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerQuickOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, DealerAccount, DealerTier, ProductVariant, Warehouse, PriceListItem} */
    private function fixture(string $stock = '10'): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $priceList = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        $price = PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        $admin = User::factory()->admin()->create();
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(), 'amount' => '100000.00', 'method' => 'other_manual'], $admin);
        if ($stock !== '0') {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id, 'quantity' => $stock,
                'operation_key' => (string) Str::uuid()], $admin->id);
        }

        return [$user, $account, $tier, $variant, $warehouse, $price];
    }

    /** @return array<string, mixed> */
    private function body(int $variantId, string $fingerprint, ?string $key = null): array
    {
        return ['operation_key' => $key ?? (string) Str::uuid(), 'review_fingerprint' => $fingerprint,
            'items' => [['product_variant_id' => $variantId, 'quantity' => '2']],
            ...$this->shipping()];
    }

    /** @return array<string, string> */
    private function shipping(string $provinceCode = '79'): array
    {
        $ward = AdministrativeWard::query()->where('province_code', $provinceCode)->firstOrFail();

        return ['recipient_name' => 'Receiving Manager', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_province_code' => $provinceCode,
            'shipping_ward_code' => $ward->code];
    }

    public function test_quick_order_only_accepts_the_authenticated_dealers_primary_account(): void
    {
        [$user, $account, $tier, $variant] = $this->fixture();
        $second = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $second->id, 'user_id' => $user->id]);
        $stranger = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        Sanctum::actingAs($user);
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];

        $review = $this->postJson("/api/dealer/accounts/{$account->id}/quick-order/review", ['items' => $items, ...$this->shipping()])
            ->assertOk()->json('data');
        $this->postJson("/api/dealer/accounts/{$second->id}/quick-order/review", ['items' => $items, ...$this->shipping()])->assertNotFound();
        $this->postJson("/api/dealer/accounts/{$second->id}/quick-order", $this->body($variant->id, $review['review_fingerprint']))->assertNotFound();
        $this->postJson("/api/dealer/accounts/{$stranger->id}/quick-order/review", ['items' => $items, ...$this->shipping()])->assertNotFound();
        $this->getJson("/api/dealer/accounts/{$stranger->id}/wallet")->assertNotFound();
        $this->getJson("/api/dealer/accounts/{$stranger->id}/tier")->assertNotFound();
        $this->getJson("/api/dealer/accounts/{$stranger->id}/orders")->assertNotFound();
        $this->postJson("/api/dealer/accounts/{$account->id}/quick-order", [
            ...$this->body($variant->id, $review['review_fingerprint']),
            'dealer_account_id' => $second->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('dealer_account_id');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_review_and_submit_snapshot_reservation_idempotency_and_history(): void
    {
        [$user, $account, $tier, $variant, $warehouse] = $this->fixture();
        $variant->product->images()->create(['product_variant_id' => $variant->id,
            'path' => 'products/quick-order-image.jpg', 'is_primary' => true]);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}";
        $review = $this->postJson("$url/quick-order/review", ['items' => [['product_variant_id' => $variant->id, 'quantity' => '2']], ...$this->shipping()])
            ->assertOk()->assertJsonPath('data.can_submit', true)->assertJsonPath('data.grand_total', '200.00')
            ->assertJsonPath('data.items.0.minimum_quantity', '2.000')->json('data');
        $this->assertStringEndsWith('/storage/products/quick-order-image.jpg', $review['items'][0]['image_url']);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $body = $this->body($variant->id, $review['review_fingerprint']);
        $order = $this->postJson("$url/quick-order", $body)->assertCreated()
            ->assertJsonPath('data.sales_channel', 'dealer')->assertJsonPath('data.order_source', 'quick_order')
            ->assertJsonPath('data.order_status', 'confirmed')->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.fulfillment_status', 'reserved')->json('data');
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'buyer_user_id' => $user->id,
            'dealer_account_id' => $account->id, 'warehouse_id' => $warehouse->id,
            'dealer_code_snapshot' => $account->code, 'effective_tier_id_snapshot' => $tier->id]);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order['id'],
            'unit_price_snapshot' => '100.00', 'minimum_quantity_snapshot' => '2.000']);
        $this->assertDatabaseHas('inventory_balances', ['product_variant_id' => $variant->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '2.000']);
        $this->postJson("$url/quick-order", $body)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->postJson("$url/quick-order", [...$body, 'recipient_name' => 'Different'])
            ->assertConflict()->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->getJson("$url/orders")->assertOk()->assertJsonPath('data.0.id', $order['id']);
        $this->getJson("$url/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.items.0.minimum_quantity', '2.000')
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id);
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("$url/orders/{$order['id']}")->assertNotFound();
    }

    public function test_address_warehouse_does_not_change_tier_price_and_order_snapshot_survives_configuration_change(): void
    {
        [$user, $account, $tier, $variant, $hcm, $price] = $this->fixture();
        $price->update(['unit_price' => '850000.00']);
        $hcm->update(['dealer_price_adjustment_percent' => '0.00']);
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        WarehouseServiceArea::query()->create(['warehouse_id' => $hanoi->id, 'province_code' => '1']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive(['warehouse_id' => $hanoi->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '5000000.00', 'method' => 'other_manual'], $admin);
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $hanoiShipping = $this->shipping('1');

        $this->postJson("$base/pricing/quote", ['product_variant_id' => $variant->id,
            'quantity' => '2'])
            ->assertOk()->assertJsonPath('data.unit_price', '850000.00');
        $this->getJson("$base/products")->assertOk()
            ->assertJsonPath('data.0.variants.0.dealer_price.unit_price', '850000.00');
        $review = $this->postJson("$base/quick-order/review", ['items' => $items,
            ...$hanoiShipping])->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '850000.00')
            ->assertJsonPath('data.grand_total', '1700000.00')->json('data');
        $this->postJson("$base/quick-order", [...$this->body($variant->id, $review['review_fingerprint']),
            ...$hanoiShipping, 'warehouse_id' => $hcm->id, 'unit_price' => '1', 'tier_id' => $tier->id])
            ->assertUnprocessable();
        $order = $this->postJson("$base/quick-order", [...$this->body($variant->id, $review['review_fingerprint']),
            ...$hanoiShipping, 'unit_price' => '1'])->assertCreated()->json('data');
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order['id'],
            'unit_price_snapshot' => '850000.00']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_id' => $order['id'],
            'warehouse_id' => $hanoi->id]);

        $hanoi->update(['dealer_price_adjustment_percent' => '5.00']);
        $this->getJson("$base/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '850000.00');
        $this->postJson("$base/pricing/quote", ['product_variant_id' => $variant->id,
            'quantity' => '2'])->assertOk()
            ->assertJsonPath('data.unit_price', '850000.00');
        $newReview = $this->postJson("$base/quick-order/review", ['items' => $items,
            ...$hanoiShipping])->assertOk()
            ->assertJsonPath('data.grand_total', '1700000.00')->json('data');
        $newOrder = $this->postJson("$base/quick-order", [...$this->body($variant->id, $newReview['review_fingerprint']),
            ...$hanoiShipping])->assertCreated()->json('data');
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $newOrder['id'],
            'unit_price_snapshot' => '850000.00']);
    }

    public function test_price_and_tier_changes_reject_stale_review_but_warehouse_price_configuration_does_not(): void
    {
        [$user, $account, $tier, $variant, $warehouse, $price] = $this->fixture();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $review = fn (): string => $this->postJson("$url/review", ['items' => $items, ...$this->shipping()])->assertOk()->json('data.review_fingerprint');
        $old = $review();
        $price->update(['unit_price' => '110.00']);
        $this->postJson($url, $this->body($variant->id, $old))->assertConflict()->assertJsonPath('code', 'DEALER_ORDER_CHANGED');
        $old = $review();
        $gold = DealerTier::factory()->create();
        $account->update(['current_tier_id' => $gold->id]);
        $this->postJson($url, $this->body($variant->id, $old))->assertConflict()->assertJsonPath('code', 'DEALER_ORDER_CHANGED');
        $account->update(['current_tier_id' => $tier->id]);
        $old = $review();
        DealerTierOverride::factory()->create(['dealer_account_id' => $account->id, 'tier_id' => $gold->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $this->postJson($url, $this->body($variant->id, $old))->assertConflict()->assertJsonPath('code', 'DEALER_ORDER_CHANGED');
        DealerTierOverride::query()->delete();
        $old = $review();
        $warehouse->update(['dealer_price_adjustment_percent' => '3.00']);
        $this->postJson($url, $this->body($variant->id, $old))->assertCreated();
        $this->assertDatabaseCount('sales_orders', 1);
    }

    public function test_moq_missing_price_stock_and_spoofed_authority_fail_closed(): void
    {
        [$user, $account, , $variant, , $price] = $this->fixture('1');
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $this->postJson("$url/review", ['items' => $items, ...$this->shipping()])->assertOk()
            ->assertJsonPath('data.can_submit', false)->assertJsonPath('data.items.0.errors.0', 'DEALER_MOQ_NOT_MET');
        $items[0]['quantity'] = '2';
        $review = $this->postJson("$url/review", ['items' => $items, ...$this->shipping()])->assertOk()
            ->assertJsonPath('data.can_submit', false)->json('data');
        $this->postJson($url, $this->body($variant->id, $review['review_fingerprint']))
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('message', "SKU {$variant->sku} chỉ còn 1 sản phẩm, nhưng bạn đang đặt 2. Vui lòng giảm số lượng.");
        $price->update(['status' => 'inactive']);
        $retailList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $retailList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '50.00']);
        $this->postJson("$url/review", ['items' => $items, ...$this->shipping()])->assertOk()
            ->assertJsonPath('data.items.0.errors.0', 'DEALER_PRICE_NOT_FOUND');
        $this->postJson("$url/review", ['items' => [[...$items[0], 'unit_price' => '1.00']],
            'tier_id' => 1, 'warehouse_id' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_review_rejects_duplicate_sku_and_unit_precision_without_order_writes(): void
    {
        [$user, $account, , $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order/review";
        $line = ['product_variant_id' => $variant->id, 'quantity' => '2'];
        $this->postJson($url, ['items' => [$line, $line], ...$this->shipping()])->assertUnprocessable()
            ->assertJsonValidationErrors('items.1.product_variant_id');
        $this->postJson($url, ['items' => [[...$line, 'quantity' => '2.5']], ...$this->shipping()])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_membership_and_suspension_block_review_and_submit(): void
    {
        [$user, $account, , $variant] = $this->fixture();
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $this->postJson("$url/review", ['items' => $items, ...$this->shipping()])->assertUnauthorized();
        Sanctum::actingAs($user);
        $fingerprint = $this->postJson("$url/review", ['items' => $items, ...$this->shipping()])->assertOk()->json('data.review_fingerprint');
        DealerAccountUser::query()->where('dealer_account_id', $account->id)->update(['status' => 'inactive']);
        $this->postJson($url, $this->body($variant->id, $fingerprint))->assertNotFound();
        DealerAccountUser::query()->where('dealer_account_id', $account->id)->update(['status' => 'active']);
        $account->update(['status' => DealerAccount::STATUS_SUSPENDED]);
        $this->postJson($url, $this->body($variant->id, $fingerprint))->assertNotFound();
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_dealer_order_detail_shows_admin_shipped_quantity_for_partial_fulfillment(): void
    {
        [$user, $account, , $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '5']];
        $fingerprint = $this->postJson("$url/quick-order/review", ['items' => $items, ...$this->shipping()])
            ->assertOk()->json('data.review_fingerprint');
        $order = $this->postJson("$url/quick-order", [
            ...$this->body($variant->id, $fingerprint), 'items' => $items,
        ])->assertCreated()->json('data');
        $this->getJson("$url/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.items.0.shipped_quantity', '0.000');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$order['id']}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order['items'][0]['id'], 'quantity' => '3']],
        ])->assertOk()->assertJsonPath('data.items.0.reservation.consumed_quantity', '3.000');

        Sanctum::actingAs($user);
        $this->getJson("$url/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('data.items.0.quantity', '5.000')
            ->assertJsonPath('data.items.0.shipped_quantity', '3.000');
    }

    public function test_account_members_share_history_and_admin_can_fulfill_or_cancel_dealer_orders(): void
    {
        [$user, $account, $tier, $variant, $warehouse] = $this->fixture();
        $coworker = User::factory()->customer()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $coworker->id]);
        $other = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $other->id, 'user_id' => $coworker->id]);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $fingerprint = $this->postJson("$url/quick-order/review", ['items' => $items, ...$this->shipping()])->json('data.review_fingerprint');
        $first = $this->postJson("$url/quick-order", $this->body($variant->id, $fingerprint))->assertCreated()->json('data');
        Sanctum::actingAs($coworker);
        $this->getJson("$url/orders/{$first['id']}")->assertOk();
        $this->getJson("$url/orders?status_group=active")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('status_counts.active', 1);
        $this->getJson("/api/dealer/accounts/{$other->id}/orders")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/dealer/accounts/{$other->id}/orders/{$first['id']}")->assertNotFound();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/sales-orders?sales_channel=dealer')->assertOk()
            ->assertJsonPath('data.0.dealer_account_id', $account->id);
        $this->getJson('/api/admin/sales-orders?sales_channel=dealer&order_source=quick_order&search='.$account->code)
            ->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $this->getJson("/api/admin/sales-orders/{$first['id']}")->assertOk()
            ->assertJsonPath('data.effective_tier_id_snapshot', $tier->id);
        $this->postJson("/api/admin/sales-orders/{$first['id']}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $first['items'][0]['id'], 'quantity' => '2']],
        ])->assertOk()->assertJsonPath('data.order_status', 'completed');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '8.000', 'reserved_quantity' => '0.000']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALES_ORDER_SHIPMENT',
            'reference_type' => 'SALES_ORDER_ITEM', 'reference_id' => (string) $first['items'][0]['id']]);
        Sanctum::actingAs($user);
        $fingerprint = $this->postJson("$url/quick-order/review", ['items' => $items, ...$this->shipping()])->json('data.review_fingerprint');
        $second = $this->postJson("$url/quick-order", $this->body($variant->id, $fingerprint))->assertCreated()->json('data');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$second['id']}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'refund_method' => 'dealer_wallet', 'reason' => 'order_cancel',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$second['id']}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Dealer requested cancellation',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        Sanctum::actingAs($coworker);
        $this->getJson("$url/orders")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('status_counts.all', 2)
            ->assertJsonPath('status_counts.completed', 1)
            ->assertJsonPath('status_counts.cancelled', 1)
            ->assertJsonPath('data.0.item_count', 1)
            ->assertJsonPath('data.0.total_quantity', '2.000')
            ->assertJsonPath('warehouses.0.id', $warehouse->id);
        $this->getJson("$url/orders?status_group=completed&search={$first['order_code']}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first['id']);
        $this->getJson("$url/orders?status_group=cancelled&payment_status=paid")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second['id']);
        $this->getJson("$url/orders?warehouse_id={$warehouse->id}&date_from=".now()->toDateString())
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("$url/orders?search=not-found")->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '8.000', 'reserved_quantity' => '0.000']);
    }
}
