<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerSalesReturnTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{int, int, User, User} */
    private function order(bool $deliver = true): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $orderId = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$orderId}/confirm", [
            'operation_key' => (string) Str::uuid(),
        ])->assertOk();
        $itemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        if ($deliver) {
            $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
                'operation_key' => (string) Str::uuid(),
                'items' => [['item_id' => $itemId, 'quantity' => '2']],
            ])->assertOk();
        }

        return [$orderId, $itemId, $admin, $customer];
    }

    /** @return array<string, mixed> */
    private function requestBody(int $itemId, string $quantity = '1'): array
    {
        return ['operation_key' => (string) Str::uuid(), 'reason_code' => 'defective',
            'items' => [['item_id' => $itemId, 'quantity' => $quantity]]];
    }

    public function test_retail_request_reserves_quantity_without_stock_or_refund_and_replays(): void
    {
        [$orderId, $itemId, $admin, $customer] = $this->order();
        $stock = DB::table('inventory_balances')->first()->on_hand_quantity;
        Sanctum::actingAs($customer);
        $endpoint = "/api/retail/orders/{$orderId}/returns";
        $body = $this->requestBody($itemId);
        $created = $this->postJson($endpoint, $body)->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.request_source', 'retail');
        $this->postJson($endpoint, $body)->assertCreated()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, $admin->unreadNotifications()->count());
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/returns')->assertOk()
            ->assertJsonPath('data.0.return_code', $created->json('data.return_code'));
        Sanctum::actingAs($customer);
        $this->getJson("/api/retail/orders/{$orderId}/return-eligibility")
            ->assertOk()->assertJsonPath('data.items.0.returnable_quantity', '1.000')
            ->assertJsonPath('data.items.0.pending_quantity', '1.000');
        $this->assertEquals($stock, DB::table('inventory_balances')->first()->on_hand_quantity);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_seven_day_limit_is_measured_from_delivery_history(): void
    {
        [$orderId, $itemId, $admin, $customer] = $this->order(false);
        Sanctum::actingAs($customer);
        $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId))
            ->assertConflict()->assertJsonPath('code', 'ORDER_NOT_DELIVERED');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $delivered = DB::table('sales_order_histories')->where('sales_order_id', $orderId)
            ->where('to_status', 'delivered')->value('created_at');
        $this->travelTo(CarbonImmutable::parse($delivered)->addDays(7));
        Sanctum::actingAs($customer);
        $this->getJson("/api/retail/orders/{$orderId}/return-eligibility")
            ->assertOk()->assertJsonPath('data.return_eligible', true);
        $this->travelTo(CarbonImmutable::parse($delivered)->addDays(7)->addSecond());
        $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId))
            ->assertConflict()->assertJsonPath('code', 'RETURN_WINDOW_EXPIRED');
        $this->travelBack();
    }

    public function test_rejection_releases_quantity_and_allows_a_new_request(): void
    {
        [$orderId, $itemId, $admin, $customer] = $this->order();
        Sanctum::actingAs($customer);
        $returnId = $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId, '2'))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId))
            ->assertConflict()->assertJsonPath('code', 'NOTHING_RETURNABLE');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/returns/{$returnId}/reject", ['reason' => 'Thiếu thông tin'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        Sanctum::actingAs($customer);
        $this->getJson("/api/retail/orders/{$orderId}/return-eligibility")
            ->assertOk()->assertJsonPath('data.items.0.returnable_quantity', '2.000');
        $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId))
            ->assertCreated();
    }

    public function test_approval_receive_inspection_and_refund_remain_independent(): void
    {
        [$orderId, $itemId, $admin, $customer] = $this->order();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$orderId}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        Sanctum::actingAs($customer);
        $created = $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId))
            ->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/returns/{$returnId}/process", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $returnItemId, 'restock_quantity' => '1']],
        ])->assertConflict();
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00',
            'refund_method' => 'cash', 'reason' => 'return', 'return_id' => $returnId,
        ])->assertConflict()->assertJsonPath('code', 'RETURN_NOT_AVAILABLE');
        $stock = DB::table('inventory_balances')->first()->on_hand_quantity;
        $this->postJson("/api/admin/returns/{$returnId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, $customer->unreadNotifications()->count());
        $this->assertEquals($stock, DB::table('inventory_balances')->first()->on_hand_quantity);
        $this->postJson("/api/admin/returns/{$returnId}/receive")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/admin/returns/{$returnId}/process", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $returnItemId, 'restock_quantity' => '1']],
        ])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('1.000', bcsub((string) DB::table('inventory_balances')->first()->on_hand_quantity, (string) $stock, 3));
        $this->assertDatabaseCount('refunds', 0);
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '101.00',
            'refund_method' => 'cash', 'reason' => 'return', 'return_id' => $returnId,
        ])->assertConflict()->assertJsonPath('code', 'REFUND_EXCEEDS_RETURN_VALUE');
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00',
            'refund_method' => 'cash', 'reason' => 'return', 'return_id' => $returnId,
        ])->assertCreated();
        Sanctum::actingAs($customer);
        $this->getJson("/api/retail/orders/{$orderId}/returns")
            ->assertOk()->assertJsonPath('data.0.refunded_amount', 100);
    }

    public function test_cross_customer_access_and_input_validation(): void
    {
        [$orderId, $itemId, , $customer] = $this->order();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("/api/retail/orders/{$orderId}/return-eligibility")->assertForbidden();
        $this->getJson("/api/retail/orders/{$orderId}/returns")->assertForbidden();
        $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId))->assertForbidden();
        Sanctum::actingAs($customer);
        $this->postJson("/api/retail/orders/{$orderId}/returns", [...$this->requestBody($itemId),
            'reason_code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/retail/orders/{$orderId}/returns", $this->requestBody($itemId, '3'))
            ->assertConflict()->assertJsonPath('code', 'RETURN_QUANTITY_EXCEEDS_FULFILLED');
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_dealer_can_request_for_own_delivered_order_but_not_another_account_order(): void
    {
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        $anotherAccount = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        $unrelatedAccount = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $customer->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $anotherAccount->id, 'user_id' => $customer->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '100000.00', 'method' => 'other_manual'], $admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        Sanctum::actingAs($customer);
        $base = "/api/dealer/accounts/{$account->id}";
        $review = $this->postJson("{$base}/quick-order/review", [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '2']],
        ])->assertOk()->json('data');
        $orderId = $this->postJson("{$base}/quick-order", [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '2']],
            'recipient_name' => 'Dealer buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
        ])->assertCreated()->json('data.id');
        $itemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        Sanctum::actingAs($customer);
        $this->getJson("{$base}/orders/{$orderId}/return-eligibility")
            ->assertOk()->assertJsonPath('data.return_eligible', true);
        $this->getJson("/api/dealer/accounts/{$anotherAccount->id}/orders/{$orderId}/returns")
            ->assertForbidden();
        $this->getJson("/api/dealer/accounts/{$unrelatedAccount->id}/orders/{$orderId}/returns")
            ->assertForbidden();
        $created = $this->postJson("{$base}/orders/{$orderId}/returns", $this->requestBody($itemId))
            ->assertCreated()->assertJsonPath('data.request_source', 'dealer');
        $returnId = $created->json('data.id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/returns/{$returnId}/approve")->assertOk();
        $this->postJson("/api/admin/returns/{$returnId}/receive")->assertOk();
        $this->postJson("/api/admin/returns/{$returnId}/process", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $created->json('data.items.0.id'), 'restock_quantity' => '1']],
        ])->assertOk();
        $refund = ['operation_key' => (string) Str::uuid(), 'amount' => '100.00',
            'reason' => 'return', 'return_id' => $returnId];
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            ...$refund, 'refund_method' => 'bank_transfer',
        ])->assertConflict()->assertJsonPath('code', 'DEALER_WALLET_REFUND_REQUIRED');
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            ...$refund, 'refund_method' => 'dealer_wallet',
        ])->assertCreated();
    }
}
