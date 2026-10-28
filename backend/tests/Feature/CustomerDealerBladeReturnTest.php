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
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDealerBladeReturnTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{SalesOrder, int, User, User} */
    private function retailOrder(bool $deliver = true): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", [
            'operation_key' => (string) Str::uuid(),
        ])->assertOk();
        $itemId = (int) DB::table('sales_order_items')->where('sales_order_id', $id)->value('id');
        if ($deliver) {
            $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
                'operation_key' => (string) Str::uuid(),
                'items' => [['item_id' => $itemId, 'quantity' => '2']],
            ])->assertOk();
        }

        return [SalesOrder::query()->findOrFail($id), $itemId, $buyer, $admin];
    }

    /** @return array{SalesOrder, int, User, DealerAccount} */
    private function dealerOrder(): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $buyer->id]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '100000.00', 'method' => 'other_manual'], $admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        Sanctum::actingAs($buyer);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $shipping = ['recipient_name' => 'Dealer buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_province_code' => '79',
            'shipping_ward_code' => $ward->code];
        $review = $this->postJson("/api/dealer/accounts/{$account->id}/quick-order/review", [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '2']], ...$shipping,
        ])->assertOk()->json('data');
        $id = $this->postJson("/api/dealer/accounts/{$account->id}/quick-order", [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '2']], ...$shipping,
        ])->assertCreated()->json('data.id');
        $itemId = (int) DB::table('sales_order_items')->where('sales_order_id', $id)->value('id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();

        return [SalesOrder::query()->findOrFail($id), $itemId, $buyer, $account];
    }

    /** @return array<string, mixed> */
    private function requestBody(int $itemId, string $quantity = '1'): array
    {
        return ['operation_key' => (string) Str::uuid(), 'reason_code' => 'defective',
            'items' => [['item_id' => $itemId, 'quantity' => $quantity]]];
    }

    public function test_return_routes_require_customer_and_active_dealer_context(): void
    {
        $this->get('/my-orders/1/returns/create')->assertRedirect('/login');
        $this->post('/my-orders/1/returns', [])->assertRedirect('/login');
        $this->get('/dealer/orders/1/returns/create')->assertRedirect('/login');
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/my-orders/1/returns/create')->assertForbidden();
        $this->get('/dealer/orders/1/returns/create')->assertForbidden();
        $this->actingAs(User::factory()->customer()->create());
        $this->get('/my-orders/999999/returns/create')->assertNotFound();
        $this->get('/dealer/orders/1/returns/create')->assertForbidden();
    }

    public function test_retail_request_replays_and_never_restocks_or_refunds(): void
    {
        [$order, $itemId, $buyer, $admin] = $this->retailOrder();
        $stock = DB::table('inventory_balances')->first()->on_hand_quantity;
        $movementCount = DB::table('stock_movements')->count();
        $this->actingAs($buyer);
        $this->get(route('retail.web.orders.show', $order->id))->assertOk()->assertSee('Yêu cầu trả hàng');
        $this->get(route('retail.web.orders.returns.create', $order->id))->assertOk()
            ->assertSee('name="operation_key"', false)->assertSee('Còn được trả');
        $body = $this->requestBody($itemId);
        $body['note'] = '<script>alert(1)</script>';
        $url = route('retail.web.orders.returns.store', $order->id);
        $this->post($url, $body)->assertRedirect();
        $entry = SalesReturn::query()->sole();
        $this->assertSame('requested', $entry->status);
        $this->assertSame('retail', $entry->request_source);
        $this->assertSame($buyer->id, $entry->requested_by_user_id);
        $this->post($url, $body)->assertRedirect(route('retail.web.orders.returns.show', [$order->id, $entry->id]));
        $this->assertDatabaseCount('sales_returns', 1);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame($stock, DB::table('inventory_balances')->first()->on_hand_quantity);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->get(route('retail.web.orders.show', $order->id))->assertOk()
            ->assertSee($entry->return_code)->assertSee('Đang xử lý');
        $this->get(route('retail.web.orders.returns.show', [$order->id, $entry->id]))
            ->assertOk()->assertSee('Chờ duyệt')->assertSee('chưa đồng nghĩa')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs(User::factory()->customer()->create())
            ->get(route('retail.web.orders.returns.show', [$order->id, $entry->id]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.web.sales.returns.show', $entry->id))
            ->assertOk()->assertSee($entry->return_code);
        $this->post(route('admin.web.sales.returns.approve', $entry->id))->assertRedirect();
        $this->actingAs($buyer)->get(route('retail.web.orders.returns.show', [$order->id, $entry->id]))
            ->assertOk()->assertSee('Đã duyệt, chờ nhận hàng');
    }

    public function test_retail_form_write_and_detail_enforce_eligibility_and_ownership(): void
    {
        [$order, $itemId, $buyer] = $this->retailOrder(false);
        $other = User::factory()->customer()->create();
        $this->actingAs($other)->get(route('retail.web.orders.returns.create', $order->id))->assertNotFound();
        $this->post(route('retail.web.orders.returns.store', $order->id), $this->requestBody($itemId))->assertNotFound();
        $this->actingAs($buyer);
        $this->get(route('retail.web.orders.returns.create', $order->id))->assertOk()
            ->assertDontSee('name="operation_key"', false)->assertSee('giao đủ');
        $this->from(route('retail.web.orders.returns.create', $order->id))
            ->post(route('retail.web.orders.returns.store', $order->id), $this->requestBody($itemId))
            ->assertSessionHasErrors('return');
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_retail_rejects_invalid_item_quantity_reason_and_expired_deadline(): void
    {
        [$order, $itemId, $buyer] = $this->retailOrder();
        $url = route('retail.web.orders.returns.store', $order->id);
        $this->actingAs($buyer);
        $this->post($url, [...$this->requestBody($itemId), 'reason_code' => 'other'])
            ->assertSessionHasErrors('note');
        $this->post($url, $this->requestBody(999999))->assertSessionHasErrors('return');
        $this->post($url, $this->requestBody($itemId, '3'))->assertSessionHasErrors('return');
        $this->assertDatabaseCount('sales_returns', 0);
        $delivered = DB::table('sales_order_histories')->where('sales_order_id', $order->id)
            ->where('to_status', 'delivered')->value('created_at');
        $this->travelTo(CarbonImmutable::parse($delivered)->addDays((int) config('returns.window_days', 7))->addSecond());
        $this->get(route('retail.web.orders.returns.create', $order->id))->assertOk()
            ->assertSee('hết thời hạn')->assertDontSee('name="operation_key"', false);
        $this->post($url, $this->requestBody($itemId))->assertSessionHasErrors('return');
        $this->travelBack();
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_dealer_return_is_scoped_to_primary_active_account_and_preserves_wallet(): void
    {
        [$order, $itemId, $buyer, $account] = $this->dealerOrder();
        $walletBefore = $account->wallet()->firstOrFail()->balance;
        $otherBuyer = User::factory()->customer()->create();
        $this->actingAs($otherBuyer)->get(route('dealer.web.orders.returns.create', $order->id))
            ->assertForbidden();
        $anotherAccount = DealerAccount::factory()->create(['current_tier_id' => $account->current_tier_id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $anotherAccount->id,
            'user_id' => $otherBuyer->id]);
        $this->get(route('dealer.web.orders.returns.create', $order->id))->assertNotFound();
        $this->actingAs($buyer);
        $this->get(route('dealer.web.orders.show', $order->id))->assertOk()->assertSee('Yêu cầu trả hàng');
        $this->get(route('dealer.web.orders.returns.create', $order->id))->assertOk()->assertSee('Còn được trả');
        $this->post(route('dealer.web.orders.returns.store', $order->id), $this->requestBody($itemId, '3'))
            ->assertSessionHasErrors('return');
        $this->assertDatabaseCount('sales_returns', 0);
        $body = $this->requestBody($itemId);
        $body['dealer_account_id'] = $anotherAccount->id;
        $this->post(route('dealer.web.orders.returns.store', $order->id), $body)->assertRedirect();
        $entry = SalesReturn::query()->sole();
        $this->assertSame('dealer', $entry->request_source);
        $this->assertSame($order->id, $entry->sales_order_id);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame($walletBefore, $account->wallet()->firstOrFail()->balance);
        $this->post(route('dealer.web.orders.returns.store', $order->id), $body)
            ->assertRedirect(route('dealer.web.orders.returns.show', [$order->id, $entry->id]));
        $this->assertDatabaseCount('sales_returns', 1);
        $this->get(route('dealer.web.orders.returns.show', [$order->id, $entry->id]))
            ->assertOk()->assertSee($entry->return_code);
        $this->actingAs($otherBuyer)->get(route('dealer.web.orders.returns.show', [$order->id, $entry->id]))
            ->assertNotFound();
        $account->update(['status' => 'suspended']);
        $this->actingAs($buyer)->get(route('dealer.web.orders.returns.show', [$order->id, $entry->id]))
            ->assertForbidden();
    }

    public function test_login_redirects_to_blade_destinations_and_keeps_local_return_paths(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $buyer = User::factory()->customer()->create(['password' => 'secret-password']);
        $login = ['email' => $buyer->email, 'password' => 'secret-password'];
        $this->post('/login', $login)->assertRedirect(route('retail.web.shop.index'));
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', [...$login, 'return_to' => '/my-orders/12/returns/create'])
            ->assertRedirect('/my-orders/12/returns/create');
        $this->post('/logout');
        $this->post('/login', [...$login, 'return_to' => 'https://evil.example/admin'])
            ->assertRedirect(route('retail.web.shop.index'));
        $this->post('/logout');
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['user_id' => $buyer->id, 'dealer_account_id' => $account->id]);
        $this->post('/login', $login)->assertRedirect(route('dealer.web.orders.index'));
        $this->post('/logout');
        $this->post('/login', [...$login, 'return_to' => '/dealer/orders/12/returns/create'])
            ->assertRedirect('/dealer/orders/12/returns/create');
        $this->post('/logout');
        $account->update(['status' => 'suspended']);
        $this->post('/login', [...$login, 'return_to' => '/dealer/orders/12/returns/create'])
            ->assertRedirect(route('retail.web.shop.index'));
    }
}
