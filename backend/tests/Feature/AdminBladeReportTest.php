<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reports_require_admin_and_validate_period_and_report_name(): void
    {
        $this->get(route('admin.web.reports.index'))->assertRedirect(route('login'));
        $this->get(route('admin.web.reports.show', 'sales'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('admin.web.reports.index'))->assertForbidden();
        $this->get(route('admin.web.reports.show', 'sales'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.web.reports.show', 'unknown'))->assertNotFound();
        $this->get(route('admin.web.reports.index', ['from' => '2026-09-28', 'to' => '2026-09-27']))->assertSessionHasErrors('to');
        $this->get(route('admin.web.reports.show', ['report' => 'sales', 'channel' => 'wholesale']))->assertSessionHasErrors('channel');
    }

    public function test_all_erp_pages_render_existing_service_contracts_without_fake_pagination(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        $this->get(route('admin.web.reports.index', $filters))
            ->assertOk()->assertSee('0 ₫')->assertSee('Báo cáo ERP');
        foreach (['sales', 'orders', 'products', 'inventory', 'procurement', 'dealers', 'wallets', 'promotions'] as $report) {
            $this->get(route('admin.web.reports.show', ['report' => $report, ...$filters]))
                ->assertOk()->assertSee('2026-09-01')->assertSee('2026-09-30')
                ->assertDontSee('Trang 1/');
        }
        $this->get(route('admin.web.reports.show', ['report' => 'inventory', ...$filters]))
            ->assertSee('Tồn thực tế')->assertSee('Khả dụng');
        $this->get(route('admin.web.reports.show', ['report' => 'procurement', ...$filters]))
            ->assertSee('Giá trị nhận ròng');
        $this->get(route('admin.web.reports.show', ['report' => 'wallets', ...$filters]))
            ->assertSee('Số dư ví không phải doanh thu');
    }

    public function test_sales_blade_and_api_use_same_settled_payment_totals(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $buyer = User::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();
        $order = SalesOrder::query()->create([
            'order_code' => 'ORD'.Str::upper(Str::random(16)),
            'creation_operation_key' => (string) Str::uuid(),
            'creation_fingerprint' => hash('sha256', (string) Str::uuid()),
            'sales_channel' => 'retail', 'order_source' => 'admin',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => $buyer->name,
            'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'order_status' => 'completed', 'pricing_context_snapshot' => 'retail',
            'subtotal' => '200000.00', 'grand_total' => '200000.00',
            'price_resolution_fingerprint' => hash('sha256', (string) Str::uuid()), 'created_by' => $admin->id,
        ]);
        $payment = Payment::factory()->create(['payment_context' => 'retail', 'amount' => '100000.00', 'status' => 'pending']);
        $payment->allocations()->create(['sales_order_id' => $order->id, 'allocated_amount' => '100000.00']);
        $payment->update(['status' => 'settled', 'settled_at' => now()]);
        $filters = ['from' => now('Asia/Ho_Chi_Minh')->toDateString(), 'to' => now('Asia/Ho_Chi_Minh')->toDateString()];

        $this->get(route('admin.web.reports.show', ['report' => 'sales', ...$filters]))
            ->assertOk()->assertSee('100.000 ₫');
        $this->getJson('/api/admin/reports/sales?'.http_build_query($filters))
            ->assertOk()->assertJsonPath('totals.gross', '100000.00')
            ->assertJsonPath('totals.refunds', '0.00')
            ->assertJsonPath('totals.net', '100000.00');
    }
}
