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
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeSalesDraftEditTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, Warehouse, ProductVariant, PriceListItem, array<string, mixed>} */
    private function fixture(): array
    {
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        $price = PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '120000.00']);
        $body = [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id, 'currency' => 'VND',
            'recipient_name' => 'Người nhận', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '123 Main Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ];

        return [$buyer, $warehouse, $variant, $price, $body];
    }

    /** @param array<string, mixed> $body */
    private function createDraft(array $body): SalesOrder
    {
        $this->post(route('admin.web.sales.orders.store'), $body)->assertRedirect();

        return SalesOrder::query()->latest('id')->firstOrFail();
    }

    public function test_only_admin_can_open_or_submit_draft_edit(): void
    {
        [$buyer, , , , $body] = $this->fixture();
        $this->actingAs(User::factory()->admin()->create());
        $order = $this->createDraft($body);
        auth()->logout();

        $this->get(route('admin.web.sales.orders.edit', $order))->assertRedirect(route('login'));
        $this->post(route('admin.web.sales.orders.draft.update', $order), $body)->assertRedirect(route('login'));
        $this->actingAs($buyer);
        $this->get(route('admin.web.sales.orders.edit', $order))->assertForbidden();
        $this->post(route('admin.web.sales.orders.draft.update', $order), $body)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.web.sales.orders.edit', 999999))->assertNotFound();
    }

    public function test_edit_form_prefills_existing_draft_and_show_links_to_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$buyer, $warehouse, $variant, , $body] = $this->fixture();
        $order = $this->createDraft($body);

        $this->get(route('admin.web.sales.orders.edit', $order))->assertOk()
            ->assertSee($order->order_code)->assertSee($buyer->name)->assertSee($variant->sku)
            ->assertSee('name="warehouse_id"', false)->assertSee('value="'.$warehouse->id.'"', false)
            ->assertViewHas('initialItems', fn (array $items): bool => $items[0]['quantity'] === '2')
            ->assertSee(route('admin.web.sales.orders.draft.update', $order));
        $this->get(route('admin.web.sales.orders.show', $order))->assertOk()
            ->assertSee(route('admin.web.sales.orders.edit', $order));
    }

    public function test_update_replaces_items_and_reprices_on_server_without_reserving_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [, , $variant, $price, $body] = $this->fixture();
        $order = $this->createDraft($body);
        $secondBuyer = User::factory()->customer()->create();
        $secondWarehouse = Warehouse::factory()->create();
        $secondVariant = ProductVariant::factory()->create(['track_inventory' => true]);
        PriceListItem::factory()->create(['price_list_id' => $price->price_list_id,
            'product_variant_id' => $secondVariant->id, 'unit_price' => '50000.00']);
        $price->update(['unit_price' => '130000.00']);
        $update = [...$body, 'operation_key' => (string) Str::uuid(),
            'buyer_user_id' => $secondBuyer->id, 'warehouse_id' => $secondWarehouse->id,
            'recipient_name' => 'Người nhận mới', 'shipping_address_line1' => '456 New Street',
            'payment_method' => 'cod', 'delivery_note' => 'Giao buổi sáng',
            'items' => [['sku' => $variant->sku, 'quantity' => '1'],
                ['sku' => $secondVariant->sku, 'quantity' => '3']]];

        $this->post(route('admin.web.sales.orders.draft.update', $order), $update)
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $order->refresh();
        $this->assertSame($secondBuyer->id, $order->buyer_user_id);
        $this->assertSame($secondWarehouse->id, $order->warehouse_id);
        $this->assertSame('Người nhận mới', $order->recipient_name);
        $this->assertSame('456 New Street', $order->shipping_address_line1);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('Giao buổi sáng', $order->delivery_note);
        $this->assertSame('280000.00', $order->grand_total);
        $this->assertSame(2, $order->items()->count());
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order->id,
            'sku_snapshot' => $variant->sku, 'unit_price_snapshot' => '130000.00']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('sales_order_histories', ['sales_order_id' => $order->id,
            'event_type' => 'draft_updated']);

        $historyCount = $order->histories()->count();
        $this->post(route('admin.web.sales.orders.draft.update', $order), $update)
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertSame($historyCount, $order->histories()->count());
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$update,
            'recipient_name' => 'Conflict with same key'])->assertSessionHasErrors('operation');
        $this->assertSame('Người nhận mới', $order->fresh()->recipient_name);

        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$update,
            'operation_key' => (string) Str::uuid(),
            'items' => [['sku' => $secondVariant->sku, 'quantity' => '3']]])
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertSame('150000.00', $order->fresh()->grand_total);
        $this->assertSame($secondVariant->sku, $order->items()->sole()->sku_snapshot);
    }

    public function test_preview_is_read_only_and_invalid_or_forged_updates_preserve_draft(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [, , $variant, , $body] = $this->fixture();
        $order = $this->createDraft($body);
        $beforeTotal = $order->grand_total;
        $beforeItems = $order->items()->count();

        $this->post(route('admin.web.sales.orders.draft.preview', $order), $body)
            ->assertRedirect(route('admin.web.sales.orders.edit', $order));
        $this->assertSame($beforeTotal, $order->fresh()->grand_total);
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'grand_total' => '1'])
            ->assertSessionHasErrors('grand_total');
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'items' => [
                ['sku' => $variant->sku, 'quantity' => '1'], ['sku' => $variant->sku, 'quantity' => '1'],
            ]])->assertSessionHasErrors('items.1.sku');
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'warehouse_id' => 999999])
            ->assertSessionHasErrors('warehouse_id');
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'recipient_phone' => ''])
            ->assertSessionHasErrors('recipient_phone');
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'items' => [
                ['sku' => $variant->sku, 'quantity' => '1'], ['sku' => 'MISSING-SKU', 'quantity' => '1'],
            ]])->assertSessionHasErrors('items');
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'items' => [['sku' => $variant->sku, 'quantity' => '0']]])
            ->assertSessionHasErrors('items.0.quantity');
        $this->assertSame($beforeTotal, $order->fresh()->grand_total);
        $this->assertSame($beforeItems, $order->items()->count());
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_updated_draft_confirms_with_only_its_current_item_quantity(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        [, $warehouse, $variant, , $body] = $this->fixture();
        $order = $this->createDraft($body);
        app(InventoryService::class)->opening([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '10', 'operation_key' => (string) Str::uuid(),
            'reason_detail' => 'Stock for draft edit integration test',
        ], $admin->id);
        $this->post(route('admin.web.sales.orders.draft.update', $order), [...$body,
            'operation_key' => (string) Str::uuid(), 'items' => [['sku' => $variant->sku, 'quantity' => '3']]])
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->post(route('admin.web.sales.orders.confirm', $order), ['operation_key' => (string) Str::uuid()])
            ->assertRedirect(route('admin.web.sales.orders.show', $order));
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'reserved_quantity' => '3.000']);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_confirmed_cart_and_dealer_orders_cannot_be_edited(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [, , , , $body] = $this->fixture();
        $confirmed = $this->createDraft($body);
        $confirmed->update(['order_status' => 'confirmed']);
        $this->get(route('admin.web.sales.orders.edit', $confirmed))->assertNotFound();
        $this->post(route('admin.web.sales.orders.draft.update', $confirmed), [...$body,
            'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('operation');

        $cancelled = $this->createDraft([...$body, 'operation_key' => (string) Str::uuid()]);
        $cancelled->update(['order_status' => 'cancelled']);
        $this->get(route('admin.web.sales.orders.edit', $cancelled))->assertNotFound();
        $this->post(route('admin.web.sales.orders.draft.update', $cancelled), [...$body,
            'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('operation');

        $cart = $this->createDraft([...$body, 'operation_key' => (string) Str::uuid()]);
        $cart->update(['order_source' => 'cart']);
        $this->get(route('admin.web.sales.orders.edit', $cart))->assertNotFound();
        $this->post(route('admin.web.sales.orders.draft.update', $cart), [...$body,
            'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('operation');

        $dealer = $this->createDraft([...$body, 'operation_key' => (string) Str::uuid()]);
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
        $this->get(route('admin.web.sales.orders.edit', $dealer))->assertNotFound();
        $this->post(route('admin.web.sales.orders.draft.update', $dealer), [...$body,
            'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('inventory_reservations', 0);
    }
}
