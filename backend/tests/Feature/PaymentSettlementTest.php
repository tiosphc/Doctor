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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentSettlementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{int, User, User} */
    private function order(): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->customer()->create();
        Sanctum::actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $id = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => 'Buyer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();

        return [$id, $admin, $buyer];
    }

    /** @return array<string, string> */
    private function payment(string $amount = '50.00', ?string $key = null): array
    {
        return ['operation_key' => $key ?? (string) Str::uuid(), 'amount' => $amount,
            'payment_method' => 'bank_transfer', 'external_reference' => 'FT-123'];
    }

    public function test_fully_paid_order_waits_for_fulfillment_then_completes_once(): void
    {
        [$id] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment('200.00'))
            ->assertCreated()->assertJsonPath('data.summary.payment_status', 'paid');
        $this->getJson("/api/admin/sales-orders/{$id}")
            ->assertOk()->assertJsonPath('data.order_status', 'confirmed');

        $itemId = $this->getJson("/api/admin/sales-orders/{$id}")->json('data.items.0.id');
        $body = ['operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $itemId, 'quantity' => '2']]];
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", $body)
            ->assertOk()->assertJsonPath('data.order_status', 'completed')
            ->assertJsonPath('data.fulfillment_status', 'fulfilled');
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", $body)
            ->assertOk()->assertJsonPath('data.order_status', 'completed');
        $this->assertDatabaseCount('sales_order_histories', 4);
    }

    public function test_partially_paid_delivered_order_completes_only_after_final_payment(): void
    {
        [$id] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment('50.00'))
            ->assertCreated()->assertJsonPath('data.summary.payment_status', 'partially_paid');
        $itemId = $this->getJson("/api/admin/sales-orders/{$id}")->json('data.items.0.id');
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk()->assertJsonPath('data.order_status', 'delivered');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            ...$this->payment('150.00'), 'external_reference' => 'FT-124',
        ])->assertCreated()->assertJsonPath('data.summary.payment_status', 'paid');
        $this->getJson("/api/admin/sales-orders/{$id}")
            ->assertOk()->assertJsonPath('data.order_status', 'completed');
    }

    public function test_partial_full_replay_overpay_and_inventory_is_unchanged(): void
    {
        [$id] = $this->order();
        $balance = DB::table('inventory_balances')->first();
        $body = $this->payment();
        $first = $this->postJson("/api/admin/sales-orders/{$id}/payments", $body)->assertCreated()
            ->assertJsonPath('data.summary.paid_amount', '50.00')
            ->assertJsonPath('data.summary.outstanding_amount', '150.00')
            ->assertJsonPath('data.summary.payment_status', 'partially_paid');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $body)->assertCreated()
            ->assertJsonPath('data.payment.id', $first->json('data.payment.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [...$body, 'amount' => '60.00'])
            ->assertConflict()->assertJsonPath('code', 'PAYMENT_OPERATION_CONFLICT');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            ...$this->payment('150.01'), 'external_reference' => 'FT-124',
        ])->assertConflict()->assertJsonPath('code', 'PAYMENT_EXCEEDS_OUTSTANDING');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            ...$this->payment('150.00'), 'external_reference' => 'FT-125',
        ])->assertCreated()->assertJsonPath('data.summary.payment_status', 'paid')
            ->assertJsonPath('data.summary.outstanding_amount', '0.00');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            ...$this->payment('1.00'), 'external_reference' => 'FT-126',
        ])->assertConflict()->assertJsonPath('code', 'ORDER_ALREADY_PAID');
        $this->getJson("/api/admin/sales-orders/{$id}/payments")->assertOk()->assertJsonCount(2, 'data.payments');
        $this->getJson("/api/admin/sales-orders/{$id}")->assertOk()
            ->assertJsonPath('data.paid_amount', '200.00')->assertJsonPath('data.outstanding_amount', '0.00');
        $this->assertEquals($balance->on_hand_quantity, DB::table('inventory_balances')->first()->on_hand_quantity);
        $this->assertEquals($balance->reserved_quantity, DB::table('inventory_balances')->first()->reserved_quantity);
    }

    public function test_validation_duplicate_reference_and_paid_cancellation(): void
    {
        [$id] = $this->order();
        foreach (['0', '1.001', '-1'] as $amount) {
            $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment($amount))
                ->assertConflict()->assertJsonPath('code', 'PAYMENT_AMOUNT_INVALID');
        }
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            ...$this->payment(), 'currency' => 'USD', 'status' => 'settled', 'dealer_account_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['currency', 'status', 'dealer_account_id']);
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            ...$this->payment(), 'external_reference' => ' ft-123 ',
        ])->assertConflict()->assertJsonPath('code', 'PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED')
            ->assertJsonPath('message', 'Khoản thanh toán này đã được ghi nhận hoặc mã giao dịch đã được dùng. Vui lòng kiểm tra lịch sử thanh toán.');
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Changed mind',
        ])->assertConflict()->assertJsonPath('code', 'PAID_ORDER_REQUIRES_REFUND')
            ->assertJsonPath('message', 'Đơn hàng đã thanh toán. Vui lòng hoàn tiền theo quy trình trước khi hủy.');
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'order_status' => 'confirmed', 'payment_status' => 'partially_paid']);
        DB::table('sales_orders')->where('id', $id)->update(['order_status' => 'completed']);
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Too late',
        ])->assertConflict()->assertJsonPath('code', 'PAID_ORDER_REQUIRES_REFUND');
    }

    public function test_settled_rows_are_immutable_and_pending_does_not_count(): void
    {
        [$id] = $this->order();
        $paymentId = $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())
            ->assertCreated()->json('data.payment.id');
        $allocationId = DB::table('payment_allocations')->where('payment_id', $paymentId)->value('id');
        try {
            DB::table('payments')->where('id', $paymentId)->update(['amount' => '1.00']);
            $this->fail('Settled payment was updated.');
        } catch (QueryException) {
            $this->assertDatabaseHas('payments', ['id' => $paymentId, 'amount' => '50.00']);
        }
        try {
            DB::table('payment_allocations')->where('id', $allocationId)->delete();
            $this->fail('Settled allocation was deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('payment_allocations', ['id' => $allocationId]);
        }
        [$otherOrderId] = $this->order();
        try {
            Payment::query()->findOrFail($paymentId)->allocations()->create([
                'sales_order_id' => $otherOrderId, 'allocated_amount' => '1.00',
            ]);
            $this->fail('An allocation was appended after settlement.');
        } catch (QueryException) {
            $this->assertDatabaseCount('payment_allocations', 1);
        }
        $pending = Payment::factory()->create(['amount' => '10.00']);
        $pending->allocations()->create(['sales_order_id' => $id, 'allocated_amount' => '10.00']);
        $this->getJson("/api/admin/sales-orders/{$id}")->assertOk()->assertJsonPath('data.paid_amount', '50.00');
    }

    public function test_retail_ownership_and_admin_only_settlement(): void
    {
        [$id, , $buyer] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())->assertCreated();
        Sanctum::actingAs($buyer);
        $this->getJson("/api/retail/orders/{$id}")->assertOk()->assertJsonPath('data.paid_amount', '50.00')
            ->assertJsonPath('data.outstanding_amount', '150.00')->assertJsonMissingPath('data.recorded_by_user_id');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())->assertForbidden();
        $this->getJson("/api/admin/sales-orders/{$id}/payments")->assertForbidden();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("/api/retail/orders/{$id}")->assertNotFound();
    }

    public function test_dealer_payment_context_uses_order_account_and_member_read_stays_scoped(): void
    {
        $dealer = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $dealer->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '1']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '5',
            'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(), 'amount' => '1000.00', 'method' => 'other_manual'], $admin);
        Sanctum::actingAs($dealer);
        $base = "/api/dealer/accounts/{$account->id}";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $fingerprint = $this->postJson("{$base}/quick-order/review", ['items' => $items])
            ->assertOk()->json('data.review_fingerprint');
        $id = $this->postJson("{$base}/quick-order", [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $fingerprint,
            'items' => $items, 'recipient_name' => 'Dealer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
        ])->assertCreated()->json('data.id');
        Sanctum::actingAs($admin);
        $this->assertDatabaseHas('payments', ['dealer_account_id' => $account->id, 'payment_method' => 'dealer_wallet', 'status' => 'settled']);
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '20.00',
            'refund_method' => 'cash', 'reason' => 'service_recovery',
            'note' => 'Internal dealer note',
        ])->assertCreated();
        Sanctum::actingAs($dealer);
        $this->getJson("{$base}/orders/{$id}")->assertOk()
            ->assertJsonPath('data.paid_amount', '100.00')
            ->assertJsonPath('data.refunded_amount', '20.00')
            ->assertJsonPath('data.net_settled_amount', '80.00')
            ->assertJsonPath('data.refunds.0.amount', '20.00')
            ->assertJsonMissing(['Internal dealer note']);
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [
            'operation_key' => (string) Str::uuid(), 'amount' => '1.00',
            'refund_method' => 'cash', 'reason' => 'other',
        ])->assertForbidden();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("{$base}/orders/{$id}")->assertNotFound();
    }

    public function test_reconciliation_repairs_derived_cache_only(): void
    {
        [$id] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())->assertCreated();
        DB::table('sales_orders')->where('id', $id)->update(['payment_status' => 'unpaid']);
        $this->artisan('payments:reconcile-orders', ['--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'unpaid']);
        $this->artisan('payments:reconcile-orders', ['--apply' => true])->assertExitCode(0);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'partially_paid']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_legacy_paid_marker_is_flagged_without_fabricating_payment(): void
    {
        [$id] = $this->order();
        DB::table('sales_orders')->where('id', $id)->update(['payment_status' => 'paid']);
        $this->artisan('payments:reconcile-orders', ['--dry-run' => true])->assertExitCode(1);
        $this->artisan('payments:reconcile-orders', ['--apply' => true])->assertExitCode(1);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'paid']);
        $this->assertDatabaseCount('payments', 0);
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())
            ->assertConflict()->assertJsonPath('code', 'PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW');
        DB::table('sales_orders')->where('id', $id)->update(['subtotal' => '0.00', 'grand_total' => '0.00', 'payment_status' => 'paid']);
        $this->artisan('payments:reconcile-orders', ['--apply' => true])->assertExitCode(1);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'paid']);
    }

    public function test_zero_total_needs_no_fake_payment_and_lists_have_balances(): void
    {
        [$id] = $this->order();
        $this->getJson('/api/admin/sales-orders')->assertOk()
            ->assertJsonPath('data.0.paid_amount', '0.00')
            ->assertJsonPath('data.0.outstanding_amount', '200.00');
        DB::table('sales_orders')->where('id', $id)->update(['grand_total' => '0.00', 'subtotal' => '0.00']);
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())
            ->assertConflict()->assertJsonPath('code', 'ORDER_ALREADY_PAID');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'unpaid']);
    }

    public function test_currency_anomaly_blocks_settlement_and_reconciliation_apply(): void
    {
        [$id] = $this->order();
        $foreignCurrency = Payment::factory()->create(['currency' => 'USD', 'amount' => '10.00']);
        $foreignCurrency->allocations()->create(['sales_order_id' => $id, 'allocated_amount' => '10.00']);
        $this->postJson("/api/admin/sales-orders/{$id}/payments", $this->payment())
            ->assertConflict()->assertJsonPath('code', 'PAYMENT_CURRENCY_MISMATCH');
        $this->artisan('payments:reconcile-orders', ['--apply' => true])->assertExitCode(1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'unpaid']);
    }
}
