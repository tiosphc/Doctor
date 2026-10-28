<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeSalesReturnRefundTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, SalesOrder, ProductVariant, Warehouse} */
    private function deliveredOrder(bool $twoItems = false): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        $variants = [$variant];
        if ($twoItems) {
            $second = ProductVariant::factory()->create(['track_inventory' => true]);
            PriceListItem::factory()->create(['price_list_id' => $priceList->id,
                'product_variant_id' => $second->id, 'unit_price' => '100.00']);
            $variants[] = $second;
        }
        $this->actingAs($admin);
        $this->post(route('admin.web.sales.orders.store'), [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => array_map(fn (ProductVariant $item): array => [
                'sku' => $item->sku, 'quantity' => '2',
            ], $variants),
        ])->assertRedirect();
        $order = SalesOrder::query()->sole();
        foreach ($variants as $item) {
            app(InventoryService::class)->opening([
                'warehouse_id' => $warehouse->id, 'product_variant_id' => $item->id,
                'quantity' => '10', 'operation_key' => (string) Str::uuid(),
                'reason_detail' => 'Opening stock for return test',
            ], $admin->id);
        }
        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => (string) Str::uuid()])->assertRedirect();
        $this->post(route('admin.web.sales.orders.fulfill', $order), [
            'operation_key' => (string) Str::uuid(),
            'items' => $order->items()->get()->map(fn ($item): array => [
                'item_id' => $item->id, 'quantity' => '2',
            ])->all(),
        ])->assertRedirect();

        return [$admin, $order, $variant, $warehouse];
    }

    private function createReturn(SalesOrder $order, string $quantity = '1', ?string $key = null): SalesReturn
    {
        $this->post(route('admin.web.sales.returns.store', $order), [
            'operation_key' => $key ?? (string) Str::uuid(), 'processing_mode' => 'pending_inspection',
            'reason' => 'Customer returned goods',
            'items' => [['item_id' => $order->items()->sole()->id,
                'quantity' => $quantity, 'restock_quantity' => '0']],
        ])->assertRedirect();

        return SalesReturn::query()->latest('id')->firstOrFail();
    }

    public function test_admin_can_list_create_and_process_return_once_without_early_restock(): void
    {
        [, $order, $variant, $warehouse] = $this->deliveredOrder();
        $this->get(route('admin.web.sales.returns.create', $order))->assertOk()->assertSee($variant->sku);
        $entry = $this->createReturn($order);
        $this->assertSame('pending', $entry->status);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '8.000']);
        $this->get(route('admin.web.sales.returns.index', ['search' => $order->order_code,
            'status' => 'pending']))->assertOk()->assertSee($entry->return_code);
        $this->get(route('admin.web.sales.returns.show', $entry))->assertOk()->assertSee('Kiểm hàng');
        $payload = ['operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $entry->items()->sole()->id,
                'restock_quantity' => '1']]];
        $this->post(route('admin.web.sales.returns.process', $entry), $payload)->assertRedirect();
        $this->post(route('admin.web.sales.returns.process', $entry), $payload)->assertRedirect();
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '9.000']);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALES_RETURN')->count());
        $this->assertSame('completed', $entry->fresh()->status);
    }

    public function test_invalid_item_and_excess_quantity_do_not_create_return(): void
    {
        [, $order] = $this->deliveredOrder();
        foreach ([[99999999, '1'], [$order->items()->sole()->id, '3']] as [$itemId, $quantity]) {
            $this->post(route('admin.web.sales.returns.store', $order), [
                'operation_key' => (string) Str::uuid(), 'reason' => 'Test',
                'processing_mode' => 'pending_inspection',
                'items' => [['item_id' => $itemId, 'quantity' => $quantity, 'restock_quantity' => '0']],
            ])->assertSessionHasErrors('operation');
        }
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_multiple_items_inspection_rolls_back_all_lines_when_one_is_invalid(): void
    {
        [, $order, , $warehouse] = $this->deliveredOrder(twoItems: true);
        $orderItems = $order->items()->orderBy('id')->get();
        $this->get(route('admin.web.sales.returns.create', $order))->assertOk()
            ->assertSee($orderItems[0]->sku_snapshot)->assertSee($orderItems[1]->sku_snapshot);
        $this->post(route('admin.web.sales.returns.store', $order), [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Both returned',
            'processing_mode' => 'pending_inspection',
            'items' => $orderItems->map(fn ($item): array => [
                'item_id' => $item->id, 'quantity' => '1', 'restock_quantity' => '0',
            ])->all(),
        ])->assertRedirect();
        $entry = SalesReturn::query()->sole();
        $returnItems = $entry->items()->orderBy('id')->get();
        $payload = ['operation_key' => (string) Str::uuid(), 'items' => [
            ['return_item_id' => $returnItems[0]->id, 'restock_quantity' => '1'],
            ['return_item_id' => $returnItems[1]->id, 'restock_quantity' => '0'],
        ]];
        $this->post(route('admin.web.sales.returns.process', $entry), $payload)
            ->assertSessionHasErrors('items.1.non_restock_reason_code');
        $this->assertSame('pending', $entry->fresh()->status);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'SALES_RETURN')->count());
        $this->post(route('admin.web.sales.returns.process', $entry), [
            ...$payload, 'items' => [$payload['items'][0],
                [...$payload['items'][1], 'non_restock_reason_code' => 'damaged']],
        ])->assertRedirect();
        $this->assertSame('completed', $entry->fresh()->status);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALES_RETURN')->count());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $orderItems[0]->product_variant_id, 'on_hand_quantity' => '9.000']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $orderItems[1]->product_variant_id, 'on_hand_quantity' => '8.000']);
    }

    public function test_guest_and_customer_cannot_manage_returns_or_refunds(): void
    {
        [, $order] = $this->deliveredOrder();
        $entry = $this->createReturn($order);
        auth()->logout();
        $this->get(route('admin.web.sales.returns.index'))->assertRedirect(route('login'));
        $this->post(route('admin.web.sales.returns.process', $entry), [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('admin.web.sales.returns.show', $entry))->assertForbidden();
        $this->post(route('admin.web.sales.returns.refunds.store', $entry), [])->assertForbidden();
        $this->assertSame('pending', $entry->fresh()->status);
    }

    public function test_refund_requires_completed_return_and_settled_payment(): void
    {
        [, $order] = $this->deliveredOrder();
        $entry = $this->createReturn($order);
        $refund = ['operation_key' => (string) Str::uuid(), 'reason' => 'return',
            'return_id' => $entry->id, 'amount' => '50.00', 'refund_method' => 'cash'];
        $this->post(route('admin.web.sales.returns.refunds.store', $entry), $refund)->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.returns.process', $entry), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $entry->items()->sole()->id, 'restock_quantity' => '0',
                'non_restock_reason_code' => 'damaged']],
        ])->assertRedirect();
        $this->post(route('admin.web.sales.returns.refunds.store', $entry), $refund)->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_web_refund_allocates_partial_settlement_and_replays_without_double_refund(): void
    {
        [, $order] = $this->deliveredOrder();
        $this->post(route('admin.web.sales.orders.payments.store', $order), [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertRedirect();
        $entry = $this->createReturn($order);
        $this->post(route('admin.web.sales.returns.process', $entry), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $entry->items()->sole()->id, 'restock_quantity' => '1']],
        ])->assertRedirect();
        $refund = ['operation_key' => (string) Str::uuid(), 'reason' => 'return',
            'return_id' => $entry->id, 'amount' => '50.00', 'refund_method' => 'cash'];
        $endpoint = route('admin.web.sales.returns.refunds.store', $entry);
        $this->post($endpoint, $refund)->assertRedirect(route('admin.web.sales.returns.show', $entry));
        $this->post($endpoint, $refund)->assertRedirect();
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('refund_allocations', ['amount' => '50.00']);
        $this->get(route('admin.web.sales.returns.show', $entry))->assertOk()->assertSee('50');
        $this->post($endpoint, [...$refund, 'operation_key' => (string) Str::uuid(), 'amount' => '51.00'])
            ->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_web_review_respects_requested_approved_pending_transitions(): void
    {
        [, $order] = $this->deliveredOrder();
        $customer = $order->buyer;
        $this->actingAs($customer);
        $this->postJson("/api/retail/orders/{$order->id}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason_code' => 'defective',
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '1']],
        ])->assertCreated();
        $entry = SalesReturn::query()->sole();
        $this->actingAs(User::query()->where('role', User::ROLE_ADMIN)->firstOrFail());
        $this->post(route('admin.web.sales.returns.receive', $entry))->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.returns.approve', $entry))->assertRedirect();
        $this->assertSame('approved', $entry->fresh()->status);
        $this->post(route('admin.web.sales.returns.approve', $entry))->assertSessionHasErrors('operation');
        $this->post(route('admin.web.sales.returns.receive', $entry))->assertRedirect();
        $this->assertSame('pending', $entry->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'SALES_RETURN')->count());
    }

    public function test_web_rejection_requires_reason_and_preserves_stock_and_refund(): void
    {
        [, $order, $variant, $warehouse] = $this->deliveredOrder();
        $this->actingAs($order->buyer);
        $this->postJson("/api/retail/orders/{$order->id}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason_code' => 'defective',
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '1']],
        ])->assertCreated();
        $entry = SalesReturn::query()->sole();
        $this->actingAs(User::query()->where('role', User::ROLE_ADMIN)->firstOrFail());
        $endpoint = route('admin.web.sales.returns.reject', $entry);
        $this->post($endpoint, [])->assertSessionHasErrors('reason');
        $this->post($endpoint, ['reason' => 'Thiếu thông tin'])->assertRedirect();
        $this->assertDatabaseHas('sales_returns', ['id' => $entry->id, 'status' => 'rejected',
            'rejection_reason' => 'Thiếu thông tin']);
        $this->post($endpoint, ['reason' => 'Again'])->assertSessionHasErrors('operation');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'on_hand_quantity' => '8.000']);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_dealer_wallet_return_refund_credits_original_wallet_once(): void
    {
        $admin = User::factory()->admin()->create();
        $dealerUser = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => DealerTier::factory()->create()->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $dealerUser->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $priceList = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $account->current_tier_id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        $this->actingAs($admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '500.00', 'method' => 'other_manual',
        ], $admin);
        $this->actingAs($dealerUser);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $shipping = ['recipient_name' => 'Receiver', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_province_code' => '79',
            'shipping_ward_code' => $ward->code];
        $review = $this->postJson("$url/review", ['items' => $items, ...$shipping])
            ->assertOk()->json('data');
        $this->postJson($url, ['operation_key' => (string) Str::uuid(),
            'review_fingerprint' => $review['review_fingerprint'], 'items' => $items,
            ...$shipping])->assertCreated();
        $order = SalesOrder::query()->sole();
        $this->actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order->id}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $order->items()->sole()->id, 'quantity' => '2']],
        ])->assertOk();
        $entry = $this->createReturn($order);
        $this->post(route('admin.web.sales.returns.process', $entry), [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $entry->items()->sole()->id, 'restock_quantity' => '1']],
        ])->assertRedirect();
        $refund = ['operation_key' => (string) Str::uuid(), 'reason' => 'return',
            'return_id' => $entry->id, 'amount' => '100.00', 'refund_method' => 'dealer_wallet'];
        $endpoint = route('admin.web.sales.returns.refunds.store', $entry);
        $this->post($endpoint, $refund)->assertRedirect();
        $this->post($endpoint, $refund)->assertRedirect();
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id,
            'balance' => '400.00']);
        $this->assertSame(1, DB::table('dealer_wallet_transactions')->where('type', 'refund_credit')->count());
        $this->post($endpoint, [...$refund, 'operation_key' => (string) Str::uuid(),
            'refund_method' => 'bank_transfer'])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_missing_return_detail_is_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.web.sales.returns.show', 99999999))->assertNotFound();
    }
}
