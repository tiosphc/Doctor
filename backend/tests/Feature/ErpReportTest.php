<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\Payment;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErpReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reports_are_admin_only_and_validate_date_range(): void
    {
        $this->getJson('/api/admin/reports/overview')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson('/api/admin/reports/overview')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/reports/overview?from=2026-09-28&to=2026-09-27')->assertUnprocessable();
        $this->getJson('/api/admin/reports/sales?channel=wholesale')->assertUnprocessable();
    }

    public function test_all_reports_return_real_empty_aggregates_without_financial_placeholders(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach (['overview', 'sales', 'orders', 'products', 'inventory', 'procurement', 'dealers', 'wallets', 'promotions', 'clinic-summary'] as $report) {
            $this->getJson("/api/admin/reports/{$report}?from=2026-09-01&to=2026-09-30")
                ->assertOk()->assertJsonPath('period.timezone', 'Asia/Ho_Chi_Minh');
        }

        $this->getJson('/api/admin/reports/sales?from=2026-09-01&to=2026-09-30')
            ->assertJsonPath('totals.gross', '0.00')
            ->assertJsonPath('totals.refunds', '0.00')
            ->assertJsonPath('totals.net', '0.00');
        $this->getJson('/api/admin/reports/clinic-summary?from=2026-09-01&to=2026-09-30')
            ->assertJsonMissingPath('revenue');
    }

    public function test_sales_reports_use_settled_allocations_and_completed_refunds_not_order_total(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $orderId = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => User::factory()->customer()->create()->id,
            'warehouse_id' => $warehouse->id, 'currency' => 'VND', 'recipient_name' => 'Buyer',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => '1 Street',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$orderId}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $date = now('Asia/Ho_Chi_Minh')->toDateString();
        $url = "/api/admin/reports/sales?from={$date}&to={$date}";

        $pending = Payment::factory()->create(['amount' => '10.00']);
        $pending->allocations()->create(['sales_order_id' => $orderId, 'allocated_amount' => '10.00']);
        $this->getJson($url)->assertOk()->assertJsonPath('totals.gross', '0.00');
        $this->postJson("/api/admin/sales-orders/{$orderId}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('totals.gross', '100.00')
            ->assertJsonPath('totals.refunds', '0.00')->assertJsonPath('totals.net', '100.00')
            ->assertJsonPath('channels.retail.net', '100.00')->assertJsonPath('channels.dealer.net', '0.00');
        $itemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $this->getJson("/api/admin/reports/products?from={$date}&to={$date}")
            ->assertJsonPath('top_skus.0.sku', $variant->sku)
            ->assertJsonPath('top_skus.0.fulfilled_quantity', '2.000');
        $this->postJson("/api/admin/sales-orders/{$orderId}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['item_id' => $itemId, 'quantity' => '1', 'restock_quantity' => '1']],
        ])->assertCreated();
        $this->getJson("/api/admin/reports/products?from={$date}&to={$date}")
            ->assertJsonPath('completed_return_quantity', '1.000');
        $this->getJson($url)->assertJsonPath('totals.net', '100.00');

        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '30.00',
            'refund_method' => 'cash', 'reason' => 'order_cancel',
        ])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('totals.gross', '100.00')
            ->assertJsonPath('totals.refunds', '30.00')->assertJsonPath('totals.net', '70.00');
        $this->getJson("{$url}&channel=dealer")->assertJsonPath('totals.net', '0.00');
        $this->getJson("/api/admin/reports/orders?from={$date}&to={$date}")
            ->assertJsonPath('summary.created_count', 1);
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'grand_total' => '200.00']);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('refund_allocations', 1);
        $this->assertSame('70.00', bcsub((string) DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')->where('p.status', 'settled')->sum('a.allocated_amount'),
            (string) DB::table('refund_allocations')->sum('amount'), 2));
    }

    public function test_procurement_report_uses_receipt_and_return_quantities_at_po_unit_price(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $supplierId = $this->postJson('/api/admin/suppliers', [
            'code' => 'REPORT-SUP', 'name' => 'Report Supplier',
        ])->assertCreated()->json('data.id');
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $order = $this->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $supplierId, 'warehouse_id' => $warehouse->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '10', 'unit_price' => '150.00']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/issue")->assertOk();
        $receipt = $this->postJson("/api/admin/purchase-orders/{$order['id']}/goods-receipts", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '4']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/admin/purchase-orders/{$order['id']}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Damaged',
            'items' => [['goods_receipt_item_id' => $receipt['items'][0]['id'], 'quantity' => '1']],
        ])->assertCreated();
        $date = now('Asia/Ho_Chi_Minh')->toDateString();
        $this->getJson("/api/admin/reports/procurement?from={$date}&to={$date}")
            ->assertOk()->assertJsonPath('summary.purchase_order_count', 1)
            ->assertJsonPath('summary.non_cancelled_ordered_value', '1500.00')
            ->assertJsonPath('summary.completed_receipt_value', '600.00')
            ->assertJsonPath('summary.purchase_return_value', '150.00')
            ->assertJsonPath('summary.net_received_value', '450.00')
            ->assertJsonPath('top_suppliers.0.net_received_value', '450.00');
        $this->getJson("/api/admin/reports/inventory?from={$date}&to={$date}&warehouse_id={$warehouse->id}")
            ->assertJsonPath('summary.on_hand', '3.000');
    }

    public function test_inventory_low_stock_summary_ignores_products_and_variants_without_tracking(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $variant->product->update(['track_inventory' => true, 'default_low_stock_threshold' => '10']);
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5',
            'operation_key' => (string) Str::uuid(),
        ], $admin->id);
        $date = now('Asia/Ho_Chi_Minh')->toDateString();
        $url = "/api/admin/reports/inventory?from={$date}&to={$date}&warehouse_id={$warehouse->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('summary.low_stock_rows', 1);
        $variant->product->update(['track_inventory' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.low_stock_rows', 0);
        $variant->product->update(['track_inventory' => true]);
        $variant->update(['track_inventory' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.low_stock_rows', 0);
    }

    public function test_sales_period_uses_settlement_and_refund_dates_independently_of_order_creation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Asia/Ho_Chi_Minh'));
        try {
            $admin = User::factory()->admin()->create();
            Sanctum::actingAs($admin);
            $warehouse = Warehouse::factory()->create();
            $variant = ProductVariant::factory()->create(['track_inventory' => true]);
            $priceList = PriceList::factory()->create();
            PriceListItem::factory()->create(['price_list_id' => $priceList->id,
                'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id, 'quantity' => '5',
                'operation_key' => (string) Str::uuid()], $admin->id);
            $orderId = $this->postJson('/api/admin/sales-orders', [
                'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
                'buyer_user_id' => User::factory()->customer()->create()->id,
                'warehouse_id' => $warehouse->id, 'currency' => 'VND', 'recipient_name' => 'Buyer',
                'recipient_phone' => '0900000000', 'shipping_address_line1' => '1 Street',
                'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
                'items' => [['sku' => $variant->sku, 'quantity' => '1']],
            ])->assertCreated()->json('data.id');
            $this->postJson("/api/admin/sales-orders/{$orderId}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
            $this->postJson("/api/admin/sales-orders/{$orderId}/payments", [
                'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
            ])->assertCreated();

            Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Ho_Chi_Minh'));
            $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
                'operation_key' => (string) Str::uuid(), 'amount' => '30.00',
                'refund_method' => 'cash', 'reason' => 'order_cancel',
            ])->assertCreated();
            $this->getJson('/api/admin/reports/sales?from=2026-09-10&to=2026-09-10')
                ->assertJsonPath('totals.gross', '100.00')->assertJsonPath('totals.refunds', '0.00');
            $this->getJson('/api/admin/reports/sales?from=2026-09-11&to=2026-09-11')
                ->assertJsonPath('totals.gross', '0.00')->assertJsonPath('totals.refunds', '30.00')
                ->assertJsonPath('totals.net', '-30.00');
            $this->getJson('/api/admin/reports/orders?from=2026-09-11&to=2026-09-11')
                ->assertJsonPath('summary.created_count', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dealer_wallet_deposit_is_separate_from_dealer_settled_revenue(): void
    {
        $admin = User::factory()->admin()->create();
        $dealer = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $dealer->id]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $priceList = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '1000.00', 'method' => 'other_manual',
        ], $admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5',
            'operation_key' => (string) Str::uuid()], $admin->id);
        Sanctum::actingAs($admin);
        $date = now('Asia/Ho_Chi_Minh')->toDateString();
        $this->getJson("/api/admin/reports/sales?from={$date}&to={$date}")
            ->assertJsonPath('totals.net', '0.00');

        Sanctum::actingAs($dealer);
        $base = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $fingerprint = $this->postJson("{$base}/review", ['items' => $items])
            ->assertOk()->json('data.review_fingerprint');
        $this->postJson($base, [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $fingerprint,
            'items' => $items, 'recipient_name' => 'Manager', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/reports/sales?from={$date}&to={$date}")
            ->assertJsonPath('totals.net', '200.00')
            ->assertJsonPath('channels.retail.net', '0.00')
            ->assertJsonPath('channels.dealer.net', '200.00');
        $this->getJson("/api/admin/reports/dealers?from={$date}&to={$date}")
            ->assertJsonPath('top_dealers.0.dealer_id', $account->id)
            ->assertJsonPath('top_dealers.0.net', '200.00')
            ->assertJsonPath('top_dealers.0.base_tier', $tier->name);
        $this->getJson("/api/admin/reports/wallets?from={$date}&to={$date}")
            ->assertJsonPath('current_balance', '800.00')
            ->assertJsonPath('completed_deposits.amount', '1000.00');
    }
}
