<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeCustomerManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_screens_require_admin_and_unknown_customer_is_not_found(): void
    {
        $this->get(route('admin.web.customers.index'))->assertRedirect(route('login'));
        $this->get(route('admin.web.customers.show', 999999))->assertRedirect(route('login'));

        $customer = Customer::factory()->guest()->create();
        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('admin.web.customers.index'))->assertForbidden();
        $this->get(route('admin.web.customers.show', $customer))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.web.customers.show', 999999))->assertNotFound();
    }

    public function test_admin_can_search_and_paginate_customer_profiles(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (range(1, 6) as $number) {
            Customer::factory()->guest()->create(['name' => 'Customer '.$number]);
        }
        $target = Customer::factory()->guest()->create(['name' => 'Nguyen Mai', 'primary_email' => 'mai@example.test']);

        $this->get(route('admin.web.customers.index'))
            ->assertOk()->assertSee('7 khách hàng')
            ->assertViewHas('customers', fn ($customers): bool => $customers->count() === 5);
        $this->get(route('admin.web.customers.index', ['page' => 2]))
            ->assertOk()->assertViewHas('customers', fn ($customers): bool => $customers->count() === 2);
        $this->get(route('admin.web.customers.index', ['search' => 'mai']))
            ->assertOk()->assertSee($target->name)->assertDontSee('Customer 1');
        $this->get(route('admin.web.customers.index', ['search' => 'mai@example.test']))
            ->assertOk()->assertSee($target->name);
    }

    public function test_customer_detail_uses_saved_orders_purchase_service_and_own_vouchers(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        $other = Customer::factory()->withUser()->create();
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create();
        $order = $this->order($buyer, $warehouse, '100000.00');
        $order->items()->create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'product_code_snapshot' => $variant->product->product_code,
            'product_name_snapshot' => 'Saved product name',
            'sku_snapshot' => 'SAVED-SKU',
            'variant_name_snapshot' => 'Saved variant',
            'unit_code_snapshot' => 'box',
            'unit_name_snapshot' => 'Box',
            'quantity' => '2',
            'pricing_context_snapshot' => 'retail',
            'price_resolution_fingerprint' => hash('sha256', (string) Str::uuid()),
            'unit_price_snapshot' => '50000.00',
            'base_amount' => '100000.00',
            'line_total' => '100000.00',
        ]);
        $payment = Payment::factory()->create(['payment_context' => 'retail', 'amount' => '100000.00', 'status' => 'pending']);
        $payment->allocations()->create(['sales_order_id' => $order->id, 'allocated_amount' => '100000.00']);
        $payment->update(['status' => 'settled', 'settled_at' => now()]);
        $voucher = Voucher::factory()->create(['user_id' => $buyer->id]);
        $otherVoucher = Voucher::factory()->create(['user_id' => $other->user_id]);

        $this->get(route('admin.web.customers.show', $customer))
            ->assertOk()->assertSee($order->order_code)->assertSee('100.000 ₫')
            ->assertSee('Saved product name')->assertSee('SAVED-SKU')
            ->assertSee($voucher->code)->assertDontSee($otherVoucher->code)
            ->assertDontSee('password')->assertDontSee('remember_token');
    }

    public function test_guest_customer_has_no_other_users_orders_or_general_vouchers(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $guest = Customer::factory()->guest()->create();
        Voucher::factory()->admin()->create(['code' => 'GENERAL-ONLY']);

        $this->get(route('admin.web.customers.show', $guest))
            ->assertOk()->assertSee('0 đơn')->assertDontSee('GENERAL-ONLY');
    }

    public function test_customer_order_history_has_independent_server_side_pagination(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $buyer = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($buyer)->create();
        $warehouse = Warehouse::factory()->create();

        foreach (range(1, 11) as $number) {
            $this->order($buyer, $warehouse, (string) ($number * 10000).'.00');
        }

        $this->get(route('admin.web.customers.show', $customer))
            ->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->total() === 11 && $orders->count() === 10);
        $this->get(route('admin.web.customers.show', ['customer' => $customer, 'orders_page' => 2]))
            ->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->total() === 11 && $orders->count() === 1);
    }

    private function order(User $buyer, Warehouse $warehouse, string $total): SalesOrder
    {
        return SalesOrder::query()->create([
            'order_code' => 'ORD'.Str::upper(Str::random(16)),
            'creation_operation_key' => (string) Str::uuid(),
            'creation_fingerprint' => hash('sha256', (string) Str::uuid()),
            'sales_channel' => 'retail',
            'order_source' => 'admin',
            'buyer_user_id' => $buyer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'VND',
            'recipient_name' => $buyer->name,
            'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM',
            'shipping_province' => 'HCM',
            'shipping_country' => 'VN',
            'order_status' => 'completed',
            'pricing_context_snapshot' => 'retail',
            'subtotal' => $total,
            'grand_total' => $total,
            'price_resolution_fingerprint' => hash('sha256', (string) Str::uuid()),
            'created_by' => $buyer->id,
        ]);
    }
}
