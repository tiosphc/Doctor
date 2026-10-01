<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\SalesVoucher;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SalesVoucherService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesVoucherTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_filters_vouchers_by_effective_status_and_can_load_edit_detail(): void
    {
        $this->getJson('/api/admin/sales-vouchers/999')->assertUnauthorized();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $current = $this->voucher($admin, ['code' => 'NOW-VOUCHER', 'normalized_code' => 'NOW-VOUCHER',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->voucher($admin, ['code' => 'LATER-VOUCHER', 'normalized_code' => 'LATER-VOUCHER',
            'starts_at' => now()->addDay()]);
        $this->voucher($admin, ['code' => 'OLD-VOUCHER', 'normalized_code' => 'OLD-VOUCHER',
            'ends_at' => now()->subDay()]);

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("/api/admin/sales-vouchers/{$current->id}")->assertForbidden();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/sales-vouchers?search=VOUCHER&status=active')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $current->id);
        $this->getJson('/api/admin/sales-vouchers?status=upcoming')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/sales-vouchers?status=expired')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/admin/sales-vouchers/{$current->id}")
            ->assertOk()->assertJsonPath('data.code', 'NOW-VOUCHER');
    }

    private function voucher(User $admin, array $attributes = []): SalesVoucher
    {
        return SalesVoucher::create([...[
            'code' => 'JUNIE10', 'normalized_code' => 'JUNIE10', 'name' => 'Welcome',
            'sales_scope' => 'retail', 'discount_type' => 'percentage', 'discount_value' => '10.00',
            'minimum_order_amount' => '0.00', 'status' => 'active', 'created_by_user_id' => $admin->id,
        ], ...$attributes]);
    }

    public function test_admin_generates_unique_retail_voucher_code_without_saving_it(): void
    {
        $this->getJson('/api/admin/sales-vouchers/generate-code')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson('/api/admin/sales-vouchers/generate-code')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $code = $this->getJson('/api/admin/sales-vouchers/generate-code')->assertOk()->json('code');
        $this->assertMatchesRegularExpression('/^VOUCHER[A-Z0-9]{8}$/', $code);
        $this->assertDatabaseMissing('sales_vouchers', ['normalized_code' => $code]);
    }

    public function test_admin_manages_retail_vouchers_separately_from_clinic_and_promotions(): void
    {
        $this->getJson('/api/admin/sales-vouchers')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson('/api/admin/sales-vouchers')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $body = ['code' => ' JUNIE10 ', 'name' => 'Welcome', 'discount_type' => 'percentage',
            'discount_value' => '10', 'minimum_order_amount' => '100', 'max_discount_amount' => null,
            'status' => 'active', 'total_usage_limit' => 2, 'per_buyer_usage_limit' => 1];
        $id = $this->postJson('/api/admin/sales-vouchers', $body)->assertCreated()
            ->assertJsonPath('data.normalized_code', 'JUNIE10')->json('data.id');
        $this->postJson('/api/admin/sales-vouchers', [...$body, 'code' => 'junie10'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/admin/sales-vouchers/$id", [...$body, 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/admin/sales-vouchers')->assertOk()->assertJsonPath('data.0.redeemed_count', 0);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('sales_promotions', 0);
    }

    public function test_voucher_quote_caps_percentage_and_rejects_dealer_and_invalid_states(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $voucher = $this->voucher($admin, ['max_discount_amount' => '150.00',
            'minimum_order_amount' => '1000.00']);
        $service = app(SalesVoucherService::class);
        $this->assertSame('150.00', $service->quote($voucher->code, 'retail', $buyer->id, '2000.00')['discount_amount']);
        $voucher->update(['discount_type' => 'fixed_amount', 'discount_value' => '70.00', 'max_discount_amount' => null]);
        $this->assertSame('70.00', $service->quote($voucher->code, 'retail', $buyer->id, '2000.00')['discount_amount']);
        foreach ([['MISSING', 'retail', 'VOUCHER_NOT_FOUND'], [$voucher->code, 'dealer', 'VOUCHER_NOT_AVAILABLE_FOR_DEALER']] as [$code, $channel, $expected]) {
            try {
                $service->quote($code, $channel, $buyer->id, '2000.00');
                $this->fail('Voucher should have been rejected.');
            } catch (HttpResponseException $exception) {
                $this->assertSame($expected, $exception->getResponse()->getData(true)['code']);
            }
        }
        $voucher->update(['ends_at' => now()->subDay()]);
        try {
            $service->quote($voucher->code, 'retail', $buyer->id, '2000.00');
            $this->fail('Expired voucher should have been rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('VOUCHER_EXPIRED', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_retail_checkout_stacks_automatic_promotion_and_manual_voucher_and_releases_usage(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_retail' => true]);
        PriceListItem::factory()->create(['price_list_id' => PriceList::factory()->create()->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5', 'operation_key' => (string) Str::uuid()], $admin->id);
        SalesPromotion::factory()->create(['discount_type' => 'fixed_amount', 'discount_value' => '20.00',
            'sales_scope' => 'retail']);
        $voucher = $this->voucher($admin, ['discount_type' => 'percentage', 'discount_value' => '10.00',
            'total_usage_limit' => 1, 'per_buyer_usage_limit' => 1]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/retail/cart/items', ['product_variant_id' => $variant->id, 'quantity' => '1'])
            ->assertOk()->assertJsonPath('data.grand_total', '80.00');
        $this->putJson('/api/retail/cart/voucher', ['code' => $voucher->code])
            ->assertOk()->assertJsonPath('data.voucher.discount_amount', '8.00')
            ->assertJsonPath('data.grand_total', '72.00');
        $fingerprint = $this->getJson('/api/retail/checkout/review')->json('data.review_fingerprint');
        $order = $this->postJson('/api/retail/checkout', [
            'checkout_operation_key' => (string) Str::uuid(), 'checkout_review_fingerprint' => $fingerprint,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000', 'shipping_address_line1' => '1 Street',
            'shipping_city' => 'HCM', 'shipping_district' => '1', 'shipping_province' => 'HCM',
            'shipping_country' => 'VN', 'payment_method' => 'cod',
        ])->assertOk()->assertJsonPath('data.grand_total', '72.00')
            ->assertJsonPath('data.items.0.discount_amount', '28.00')->json('data');
        $this->assertDatabaseHas('sales_voucher_redemptions', ['sales_order_id' => $order['id'], 'status' => 'redeemed']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/sales-orders/{$order['id']}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Buyer request',
        ])->assertOk();
        $this->assertDatabaseHas('sales_voucher_redemptions', ['sales_order_id' => $order['id'], 'status' => 'released']);
    }

    public function test_retail_voucher_validates_amount_dates_and_limits(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $voucher = $this->voucher($admin, ['minimum_order_amount' => '100.00']);
        $service = app(SalesVoucherService::class);
        $cases = [
            ['100.00', 'VOUCHER_MINIMUM_NOT_MET'],
        ];
        foreach ($cases as [$amount, $code]) {
            try {
                $service->quote($voucher->code, 'retail', $buyer->id, bcsub($amount, '0.01', 2));
                $this->fail('Voucher minimum should reject the quote.');
            } catch (HttpResponseException $exception) {
                $this->assertSame($code, $exception->getResponse()->getData(true)['code']);
            }
        }
        $voucher->update(['starts_at' => now()->addDay()]);
        try {
            $service->quote($voucher->code, 'retail', $buyer->id, '100.00');
            $this->fail('Future voucher should have been rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('VOUCHER_NOT_STARTED', $exception->getResponse()->getData(true)['code']);
        }
    }
}
