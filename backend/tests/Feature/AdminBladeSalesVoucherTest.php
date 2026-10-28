<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesVoucher;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\RetailCheckoutService;
use App\Services\SalesVoucherService;
use App\Services\SalesVoucherWriteService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeSalesVoucherTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function voucher(string $code, array $overrides = []): array
    {
        return [...['code' => $code, 'name' => 'Voucher Retail', 'discount_type' => 'percentage',
            'discount_value' => '10', 'status' => 'active'], ...$overrides];
    }

    public function test_voucher_routes_require_admin_and_invalid_ids_return_not_found(): void
    {
        $this->withoutVite();
        $this->get('/admin/sales-vouchers')->assertRedirect('/login');
        $this->post('/admin/sales-vouchers', [])->assertRedirect('/login');

        $this->actingAs(User::factory()->customer()->create());
        $this->get('/admin/sales-vouchers')->assertForbidden();
        $this->post('/admin/sales-vouchers', [])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/sales-vouchers/999999')->assertNotFound();
        $this->get('/admin/sales-vouchers/999999/edit')->assertNotFound();
    }

    public function test_list_filters_effective_status_and_create_form_renders(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $current = app(SalesVoucherWriteService::class)->create($this->voucher('WEB-NOW'), $admin);
        app(SalesVoucherWriteService::class)->create($this->voucher('WEB-LATER', [
            'starts_at' => now()->addDay()->toDateTimeString(),
        ]), $admin);
        app(SalesVoucherWriteService::class)->create($this->voucher('WEB-OLD', [
            'ends_at' => now()->subDay()->toDateTimeString(),
        ]), $admin);
        app(SalesVoucherWriteService::class)->create($this->voucher('WEB-OFF', [
            'status' => 'inactive',
        ]), $admin);

        $this->get('/admin/sales-vouchers?status=active&search=WEB')
            ->assertOk()->assertSee($current->code)->assertDontSee('WEB-LATER')->assertDontSee('WEB-OLD');
        $this->get('/admin/sales-vouchers?status=upcoming')->assertOk()->assertSee('WEB-LATER');
        $this->get('/admin/sales-vouchers?status=expired')->assertOk()->assertSee('WEB-OLD');
        $this->get('/admin/sales-vouchers?status=inactive')->assertOk()->assertSee('WEB-OFF');
        $this->get('/admin/sales-vouchers/create')->assertOk()->assertSee('Tạo voucher');
    }

    public function test_percentage_and_fixed_vouchers_are_created_for_retail_and_quote_without_redeeming(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $this->actingAs($admin);

        $this->post('/admin/sales-vouchers', $this->voucher(' WEB-10 ', [
            'minimum_order_amount' => '100', 'max_discount_amount' => '15', 'total_usage_limit' => '1',
        ]))->assertRedirect();
        $percentage = SalesVoucher::query()->where('normalized_code', 'WEB-10')->firstOrFail();
        $this->post('/admin/sales-vouchers', $this->voucher('WEB-FIXED', [
            'discount_type' => 'fixed_amount', 'discount_value' => '30',
        ]))->assertRedirect();
        $fixed = SalesVoucher::query()->where('normalized_code', 'WEB-FIXED')->firstOrFail();
        $service = app(SalesVoucherService::class);

        $this->assertSame('15.00', $service->quote($percentage->code, 'retail', $buyer->id, '200.00')['discount_amount']);
        $this->assertSame('30.00', $service->quote($fixed->code, 'retail', $buyer->id, '200.00')['discount_amount']);
        $this->assertDatabaseHas('sales_vouchers', ['id' => $percentage->id, 'sales_scope' => 'retail',
            'created_by_user_id' => $admin->id]);
        $this->assertDatabaseCount('sales_voucher_redemptions', 0);
        $this->get('/admin/sales-vouchers/'.$fixed->id)->assertOk()->assertSee('WEB-FIXED');
        try {
            $service->quote($fixed->code, 'dealer', $buyer->id, '200.00');
            $this->fail('Dealer must not receive a Retail voucher.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('VOUCHER_NOT_AVAILABLE_FOR_DEALER', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_voucher_validation_rejects_duplicate_code_invalid_discount_dates_and_scope(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $url = '/admin/sales-vouchers';
        $this->post($url, $this->voucher('WEB-UNIQUE'))->assertRedirect();

        $this->from($url.'/create')->post($url, $this->voucher('web-unique'))->assertSessionHasErrors('code');
        $this->from($url.'/create')->post($url, $this->voucher('WEB-BAD-PERCENT', [
            'discount_value' => '101',
        ]))->assertSessionHasErrors('discount_value');
        $this->from($url.'/create')->post($url, $this->voucher('WEB-BAD-DATE', [
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString(),
        ]))->assertSessionHasErrors('ends_at');
        $this->from($url.'/create')->post($url, $this->voucher('WEB-DEALER', [
            'sales_scope' => 'dealer',
        ]))->assertSessionHasErrors('sales_scope');
        $this->assertDatabaseCount('sales_vouchers', 1);
    }

    public function test_edit_changes_supported_fields_and_status_without_changing_code_identity(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->post('/admin/sales-vouchers', $this->voucher('WEB-EDIT'))->assertRedirect();
        $voucher = SalesVoucher::query()->where('normalized_code', 'WEB-EDIT')->firstOrFail();

        $this->get('/admin/sales-vouchers/'.$voucher->id.'/edit')->assertOk()->assertSee('WEB-EDIT');
        $this->put('/admin/sales-vouchers/'.$voucher->id, $this->voucher('WEB-EDIT', [
            'discount_type' => 'fixed_amount', 'discount_value' => '250', 'status' => 'inactive',
        ]))->assertRedirect();
        $this->assertDatabaseHas('sales_vouchers', ['id' => $voucher->id, 'normalized_code' => 'WEB-EDIT',
            'discount_type' => 'fixed_amount', 'discount_value' => '250.00', 'status' => 'inactive']);
        $this->get('/admin/sales-vouchers/'.$voucher->id)->assertOk()->assertSee('Đã tắt');
        $this->assertDatabaseCount('sales_voucher_redemptions', 0);
    }

    public function test_blade_created_voucher_redeems_once_at_checkout_and_used_code_stays_locked(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id,
            'unit_price' => '120000.00',
        ]);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5',
            'operation_key' => (string) Str::uuid(),
        ], $admin->id);

        $this->actingAs($admin)->post('/admin/sales-vouchers', $this->voucher('WEB-CHECKOUT'))
            ->assertRedirect();
        $voucher = SalesVoucher::query()->where('normalized_code', 'WEB-CHECKOUT')->firstOrFail();

        $this->actingAs($buyer);
        $this->post(route('retail.web.cart.items.add'), [
            'product_variant_id' => $variant->id,
            'quantity' => '1',
        ])->assertRedirect(route('retail.web.cart.index'));
        $this->put(route('retail.web.cart.voucher'), ['code' => 'web-checkout'])
            ->assertRedirect(route('retail.web.cart.index'));
        $this->get(route('retail.web.checkout.create'))->assertOk()->assertSee('WEB-CHECKOUT');
        $this->assertDatabaseCount('sales_voucher_redemptions', 0);

        $fingerprint = app(RetailCheckoutService::class)->review($buyer->id)['review_fingerprint'];
        $checkout = [
            'checkout_operation_key' => (string) Str::uuid(),
            'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Voucher Buyer',
            'recipient_phone' => '0900000000',
            'shipping_address_line1' => '123 Street',
            'shipping_city' => 'HCM',
            'shipping_district' => 'District 1',
            'shipping_province' => 'HCM',
            'shipping_country' => 'VN',
            'payment_method' => 'cod',
        ];
        $this->post(route('retail.web.checkout.store'), $checkout)->assertRedirect();
        $order = SalesOrder::query()->sole();
        $this->assertSame($voucher->id, $order->sales_voucher_id);
        $this->assertSame('WEB-CHECKOUT', $order->sales_voucher_code_snapshot);
        $this->assertSame('108000.00', $order->grand_total);
        $this->assertDatabaseHas('sales_voucher_redemptions', [
            'sales_order_id' => $order->id,
            'status' => 'redeemed',
        ]);
        $this->post(route('retail.web.checkout.store'), $checkout)
            ->assertRedirect(route('retail.web.orders.show', $order->id));
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_voucher_redemptions', 1);

        $this->actingAs($admin)->from('/admin/sales-vouchers/'.$voucher->id.'/edit')
            ->put('/admin/sales-vouchers/'.$voucher->id, $this->voucher('WEB-RENAMED'))
            ->assertSessionHasErrors('code');
        $this->assertDatabaseHas('sales_vouchers', [
            'id' => $voucher->id,
            'normalized_code' => 'WEB-CHECKOUT',
        ]);
        $this->get('/admin/sales-vouchers/'.$voucher->id)->assertOk()->assertSee($order->order_code);
    }
}
