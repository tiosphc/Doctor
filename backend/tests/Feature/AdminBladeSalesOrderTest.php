<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBladeSalesOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{0: User, 1: Warehouse, 2: ProductVariant} */
    private function catalog(): array
    {
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id, 'unit_price' => '120000.00']);

        return [$buyer, $warehouse, $variant];
    }

    /** @return array<string, mixed> */
    private function body(User $buyer, Warehouse $warehouse, ProductVariant $variant): array
    {
        return [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => 'Khách nhận hàng',
            'recipient_phone' => '0900000000', 'recipient_email' => 'recipient@example.com',
            'shipping_address_line1' => '123 Main Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ];
    }

    public function test_sales_order_blade_routes_require_admin_session(): void
    {
        $this->get(route('admin.web.sales.orders.index'))->assertRedirect(route('login'));
        $this->get(route('admin.web.sales.orders.create'))->assertRedirect(route('login'));
        $this->get(route('admin.web.sales.orders.options', ['type' => 'buyer']))->assertRedirect(route('login'));
        $this->post(route('admin.web.sales.orders.preview'))->assertRedirect(route('login'));
        $this->post(route('admin.web.sales.orders.store'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('admin.web.sales.orders.index'))->assertForbidden();
        $this->get(route('admin.web.sales.orders.create'))->assertForbidden();
        $this->get(route('admin.web.sales.orders.options', ['type' => 'buyer']))->assertForbidden();
        $this->post(route('admin.web.sales.orders.preview'))->assertForbidden();
        $this->post(route('admin.web.sales.orders.store'))->assertForbidden();
        $this->get(route('admin.web.sales.orders.show', 999999))->assertNotFound();
    }

    public function test_admin_can_create_retail_draft_and_view_snapshot_history_without_reservation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [$buyer, $warehouse, $variant] = $this->catalog();
        $body = $this->body($buyer, $warehouse, $variant);

        $this->get(route('admin.web.sales.orders.create'))->assertOk()->assertSee('Tạo đơn Retail nháp');
        $this->post(route('admin.web.sales.orders.store'), $body)->assertRedirect();
        $order = SalesOrder::query()->sole();
        $this->assertSame('draft', $order->order_status);
        $this->assertSame('retail', $order->sales_channel);
        $this->assertSame('240000.00', $order->grand_total);
        $this->assertSame('120000.00', $order->items()->sole()->unit_price_snapshot);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee($order->order_code)->assertSee($variant->sku)->assertSee('Khách nhận hàng')
            ->assertSee('Lịch sử đơn hàng')->assertSee('created')->assertSee('120.000');
        PriceListItem::query()->where('product_variant_id', $variant->id)->update(['unit_price' => '130000.00']);
        $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee('120.000')->assertDontSee('130.000');
        $this->actingAs($buyer)->get(route('admin.web.sales.orders.show', $order))->assertForbidden();
        $this->actingAs($admin);
        $this->get(route('admin.web.sales.orders.show', 999999))->assertNotFound();

        $this->post(route('admin.web.sales.orders.store'), $body)
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertDatabaseCount('sales_orders', 1);
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'recipient_name' => 'Khác'])
            ->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('sales_orders', 1);
    }

    public function test_preview_uses_server_price_and_does_not_write_an_order(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$buyer, $warehouse, $variant] = $this->catalog();
        $body = $this->body($buyer, $warehouse, $variant);

        $this->post(route('admin.web.sales.orders.preview'), [
            'warehouse_id' => $warehouse->id, 'items' => $body['items'],
        ])
            ->assertRedirect(route('admin.web.sales.orders.create'))
            ->assertSessionHas('preview.grand_total', '240000.00')
            ->assertSessionHas('preview.items.0.insufficient_stock', true);
        $this->get(route('admin.web.sales.orders.create'))->assertOk()
            ->assertSee($variant->sku)->assertSee('240.000');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        PriceListItem::query()->where('product_variant_id', $variant->id)->update(['unit_price' => '130000.00']);
        $this->post(route('admin.web.sales.orders.store'), $body)->assertRedirect();
        $this->assertSame('260000.00', SalesOrder::query()->sole()->grand_total);
    }

    public function test_form_rejects_forged_price_dealer_confirm_and_non_customer_buyer(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [$buyer, $warehouse, $variant] = $this->catalog();
        $body = $this->body($buyer, $warehouse, $variant);

        $this->post(route('admin.web.sales.orders.store'), [...$body, 'unit_price' => '1'])
            ->assertSessionHasErrors('unit_price');
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'sales_channel' => 'dealer'])
            ->assertSessionHasErrors('sales_channel');
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'confirm' => '1'])
            ->assertSessionHasErrors('confirm');
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'buyer_user_id' => $admin->id])
            ->assertSessionHasErrors('buyer_user_id');
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'items' => [$body['items'][0], $body['items'][0]]])
            ->assertSessionHasErrors('items.1.sku');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_invalid_warehouse_sku_and_quantity_cannot_create_order(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$buyer, $warehouse, $variant] = $this->catalog();
        $body = $this->body($buyer, $warehouse, $variant);

        $this->post(route('admin.web.sales.orders.store'), [...$body, 'warehouse_id' => 999999])
            ->assertSessionHasErrors('warehouse_id');
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'items' => [['sku' => $variant->sku, 'quantity' => '0']]])
            ->assertSessionHasErrors('items.0.quantity');
        $this->post(route('admin.web.sales.orders.store'), [...$body, 'items' => [['sku' => 'MISSING-SKU', 'quantity' => '1']]])
            ->assertSessionHasErrors('items');
        $warehouse->update(['status' => 'inactive']);
        $this->post(route('admin.web.sales.orders.store'), $body)->assertSessionHasErrors('warehouse_id');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_options_only_include_customers_and_sellable_retail_skus(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [$buyer, , $variant] = $this->catalog();
        $hidden = ProductVariant::factory()->create(['sellable_retail' => false, 'track_inventory' => true]);

        $this->getJson(route('admin.web.sales.orders.options', ['type' => 'buyer', 'search' => $buyer->email]))
            ->assertOk()->assertJsonPath('data.0.id', $buyer->id);
        $this->getJson(route('admin.web.sales.orders.options', ['type' => 'buyer', 'search' => $admin->email]))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('admin.web.sales.orders.options', ['type' => 'sku', 'search' => $variant->sku]))
            ->assertOk()->assertJsonPath('data.0.sku', $variant->sku);
        $this->getJson(route('admin.web.sales.orders.options', ['type' => 'sku', 'search' => $hidden->sku]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_filters_retail_and_dealer_orders(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$buyer, $warehouse, $variant] = $this->catalog();
        $this->post(route('admin.web.sales.orders.store'), $this->body($buyer, $warehouse, $variant))->assertRedirect();
        $retail = SalesOrder::query()->sole();
        $this->post(route('admin.web.sales.orders.store'), $this->body($buyer, $warehouse, $variant))->assertRedirect();
        $dealer = SalesOrder::query()->latest('id')->firstOrFail();
        $account = DealerAccount::factory()->create();
        $tier = DealerTier::factory()->create();
        $dealer->update([
            'sales_channel' => 'dealer', 'order_source' => 'quick_order',
            'dealer_account_id' => $account->id, 'dealer_code_snapshot' => $account->code,
            'dealer_name_snapshot' => $account->legal_name,
            'effective_tier_id_snapshot' => $tier->id, 'effective_tier_code_snapshot' => $tier->code,
            'effective_tier_name_snapshot' => $tier->name, 'tier_source_snapshot' => 'default',
            'pricing_context_snapshot' => 'dealer',
        ]);

        $this->get(route('admin.web.sales.orders.index'))->assertOk()
            ->assertSee($retail->order_code)->assertSee($dealer->order_code);
        $this->get(route('admin.web.sales.orders.index', ['sales_channel' => 'retail', 'search' => $retail->order_code]))
            ->assertOk()->assertSee($retail->order_code)->assertDontSee($dealer->order_code);
        $this->get(route('admin.web.sales.orders.index', ['sales_channel' => 'dealer']))
            ->assertOk()->assertSee($dealer->order_code)->assertDontSee($retail->order_code);
        $this->get(route('admin.web.sales.orders.index', ['order_status' => 'completed']))
            ->assertOk()->assertSee('Không tìm thấy đơn hàng phù hợp.')
            ->assertDontSee($retail->order_code)->assertDontSee($dealer->order_code);
    }

    public function test_list_paginates_and_preserves_filter_query(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$buyer, $warehouse, $variant] = $this->catalog();
        $this->post(route('admin.web.sales.orders.store'), $this->body($buyer, $warehouse, $variant))->assertRedirect();
        $first = SalesOrder::query()->sole();
        for ($index = 1; $index <= 20; $index++) {
            $copy = $first->replicate();
            $copy->order_code = 'TEST'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
            $copy->creation_operation_key = (string) Str::uuid();
            $copy->save();
        }
        session()->forget('success');

        $response = $this->get(route('admin.web.sales.orders.index', ['sales_channel' => 'retail']));
        $response->assertOk()->assertSee('21 đơn')->assertSee('page=2')
            ->assertSee('sales_channel=retail')->assertDontSee($first->order_code);
        $pageTwo = $this->get(route('admin.web.sales.orders.index', ['sales_channel' => 'retail', 'page' => 2]));
        $pageTwo->assertOk();
        $this->assertSame($first->id, $pageTwo->viewData('orders')->first()->id);
    }

    public function test_shared_list_query_preserves_admin_api_response(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [$buyer, $warehouse, $variant] = $this->catalog();
        $this->post(route('admin.web.sales.orders.store'), $this->body($buyer, $warehouse, $variant))->assertRedirect();
        $order = SalesOrder::query()->sole();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/sales-orders?per_page=1&sales_channel=retail&search='.$order->order_code)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.paid_amount', '0.00');
    }
}
