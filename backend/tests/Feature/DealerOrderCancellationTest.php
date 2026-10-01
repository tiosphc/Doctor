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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerOrderCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, DealerAccount, ProductVariant, Warehouse, array<string, mixed>} */
    private function paidOrder(): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $priceList = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '215000.00', 'minimum_quantity' => '1']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '71',
            'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '20000000.00', 'method' => 'other_manual',
        ], $admin);
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '30']];
        $review = $this->postJson("$base/quick-order/review", ['items' => $items])->assertOk()->json('data');
        $order = $this->postJson("$base/quick-order", [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => $items, 'recipient_name' => 'Receiving Manager', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
        ])->assertCreated()->assertJsonPath('data.grand_total', '6450000.00')->json('data');

        return [$user, $account, $variant, $warehouse, $order];
    }

    public function test_dealer_cancellation_refunds_wallet_and_releases_stock_once(): void
    {
        [, $account, $variant, $warehouse, $order] = $this->paidOrder();
        $url = "/api/dealer/accounts/{$account->id}/orders/{$order['id']}/cancel";
        $body = ['operation_key' => (string) Str::uuid()];

        $this->postJson($url, $body)->assertOk()
            ->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('data.fulfillment_status', 'unfulfilled')
            ->assertJsonPath('data.refunded_amount', '6450000.00')
            ->assertJsonPath('data.refund_status', 'fully_refunded');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        $this->postJson($url, ['operation_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');

        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id,
            'balance' => '20000000.00']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '71.000', 'reserved_quantity' => '0.000']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_id' => $order['id'],
            'released_quantity' => '30.000', 'status' => 'released']);
        $this->assertDatabaseHas('refunds', ['sales_order_id' => $order['id'],
            'refund_method' => 'dealer_wallet', 'amount' => '6450000.00', 'status' => 'completed']);
        $this->assertDatabaseHas('refund_allocations', ['amount' => '6450000.00']);
        $this->assertDatabaseHas('dealer_wallet_transactions', ['sales_order_id' => $order['id'],
            'type' => 'order_debit', 'amount' => '6450000.00']);
        $this->assertDatabaseHas('dealer_wallet_transactions', ['type' => 'refund_credit',
            'amount' => '6450000.00']);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('refund_allocations', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 3);
    }

    public function test_cancellation_requires_the_primary_account_and_the_orders_own_account(): void
    {
        [$user, $account, , , $order] = $this->paidOrder();
        $body = ['operation_key' => (string) Str::uuid()];
        $url = "/api/dealer/accounts/{$account->id}/orders/{$order['id']}/cancel";
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson($url, $body)->assertNotFound();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson($url, $body)->assertNotFound();

        $second = DealerAccount::factory()->create(['current_tier_id' => $account->current_tier_id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $second->id, 'user_id' => $user->id]);
        Sanctum::actingAs($user);
        $this->postJson("/api/dealer/accounts/{$second->id}/orders/{$order['id']}/cancel", $body)->assertNotFound();
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('operation_key');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'order_status' => 'confirmed']);
    }

    public function test_shipped_order_cannot_be_cancelled_or_refunded_by_dealer(): void
    {
        [$user, $account, $variant, $warehouse, $order] = $this->paidOrder();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$order['id']}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order['items'][0]['id'], 'quantity' => '30']],
        ])->assertOk()->assertJsonPath('data.order_status', 'completed');
        Sanctum::actingAs($user);
        $this->postJson("/api/dealer/accounts/{$account->id}/orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'ORDER_INVALID_STATE');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id,
            'balance' => '13550000.00']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '41.000', 'reserved_quantity' => '0.000']);
    }

    public function test_cancellation_refunds_only_the_remaining_settled_wallet_amount(): void
    {
        [$user, $account, , , $order] = $this->paidOrder();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/sales-orders/{$order['id']}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '2000000.00',
            'refund_method' => 'dealer_wallet', 'reason' => 'order_cancel',
        ])->assertCreated();
        Sanctum::actingAs($user);
        $this->postJson("/api/dealer/accounts/{$account->id}/orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.refunded_amount', '6450000.00')
            ->assertJsonPath('data.refundable_amount', '0.00');
        $this->assertDatabaseHas('refunds', ['sales_order_id' => $order['id'],
            'amount' => '4450000.00', 'refund_method' => 'dealer_wallet']);
        $this->assertDatabaseCount('refunds', 2);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id,
            'balance' => '20000000.00']);
    }

    public function test_refund_and_wallet_credit_roll_back_if_inventory_release_fails(): void
    {
        [, $account, $variant, $warehouse, $order] = $this->paidOrder();
        DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)->delete();
        $this->postJson("/api/dealer/accounts/{$account->id}/orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('refund_allocations', 0);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id,
            'balance' => '13550000.00']);
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'order_status' => 'confirmed']);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_id' => $order['id'],
            'released_quantity' => '0.000', 'status' => 'active']);
    }
}
