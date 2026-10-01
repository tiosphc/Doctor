<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerAutoTierService;
use App\Services\DealerNetRevenueService;
use App\Services\DealerWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerAutoTierTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{DealerAccount, DealerTier, DealerTier, User} */
    private function fixture(): array
    {
        $admin = User::factory()->admin()->create();
        $silver = DealerTier::factory()->create(['code' => 'SILVER', 'sort_order' => 1,
            'is_default_initial' => true, 'revenue_threshold' => '0.00']);
        $gold = DealerTier::factory()->create(['code' => 'GOLD', 'sort_order' => 2,
            'revenue_threshold' => '100.00']);
        DealerTier::factory()->create(['code' => 'DIAMOND', 'sort_order' => 3,
            'revenue_threshold' => '300.00']);
        $account = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);

        return [$account, $silver, $gold, $admin];
    }

    private function order(DealerAccount $account, DealerTier $tier, string $total = '200.00'): SalesOrder
    {
        $user = User::factory()->customer()->create();

        return SalesOrder::query()->create([
            'order_code' => 'ORD'.Str::upper(Str::random(20)),
            'creation_operation_key' => (string) Str::uuid(), 'creation_fingerprint' => str_repeat('a', 64),
            'sales_channel' => 'dealer', 'order_source' => 'quick_order',
            'buyer_user_id' => $user->id, 'dealer_account_id' => $account->id,
            'dealer_code_snapshot' => $account->code, 'dealer_name_snapshot' => $account->legal_name,
            'effective_tier_id_snapshot' => $tier->id, 'effective_tier_code_snapshot' => $tier->code,
            'effective_tier_name_snapshot' => $tier->name, 'tier_source_snapshot' => 'current',
            'warehouse_id' => Warehouse::factory()->create()->id, 'currency' => 'VND',
            'recipient_name' => 'Dealer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'order_status' => 'confirmed', 'pricing_context_snapshot' => 'dealer',
            'subtotal' => $total, 'grand_total' => $total,
            'price_resolution_fingerprint' => str_repeat('b', 64), 'created_by' => $user->id,
        ]);
    }

    private function payment(SalesOrder $order, User $admin, string $amount, string $status = 'settled'): Payment
    {
        $payment = Payment::factory()->create(['payment_context' => $order->sales_channel,
            'dealer_account_id' => $order->dealer_account_id, 'amount' => $amount,
            'recorded_by_user_id' => $admin->id]);
        $payment->allocations()->create(['sales_order_id' => $order->id, 'allocated_amount' => $amount]);
        $payment->update(['status' => $status, 'settled_at' => $status === 'settled' ? now() : null]);

        return $payment->refresh();
    }

    public function test_net_revenue_uses_only_settled_dealer_allocations_less_completed_refunds(): void
    {
        [$account, $silver, , $admin] = $this->fixture();
        $order = $this->order($account, $silver);
        $settled = $this->payment($order, $admin, '100.25');
        $this->payment($order, $admin, '20.00', 'pending');
        $refund = Refund::factory()->forOrder($order)->create(['amount' => '10.10',
            'processed_by_user_id' => $admin->id]);
        $refund->allocations()->create(['payment_allocation_id' => $settled->allocations()->firstOrFail()->id,
            'amount' => '10.10']);
        $refund->update(['status' => 'completed', 'completed_at' => now()]);
        $pendingRefund = Refund::factory()->forOrder($order)->create(['amount' => '5.00',
            'processed_by_user_id' => $admin->id]);
        $pendingRefund->allocations()->create(['payment_allocation_id' => $settled->allocations()->firstOrFail()->id,
            'amount' => '5.00']);
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '1000.00', 'method' => 'other_manual',
        ], $admin);

        $other = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $this->payment($this->order($other, $silver), $admin, '200.00');
        $retail = $this->order($account, $silver);
        DB::table('sales_orders')->where('id', $retail->id)->update([
            'sales_channel' => 'retail', 'dealer_account_id' => null, 'pricing_context_snapshot' => 'retail',
        ]);
        $this->payment($retail->refresh(), $admin, '30.00');

        $result = app(DealerNetRevenueService::class)->forAccount($account);
        $this->assertSame('100.25', $result['settled']);
        $this->assertSame('10.10', $result['refunded']);
        $this->assertSame('90.15', $result['net']);
    }

    public function test_realtime_upgrade_uses_highest_eligible_tier_and_never_downgrades(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(12, 0));
        [$account, $silver, $gold, $admin] = $this->fixture();
        $diamond = DealerTier::query()->where('code', 'DIAMOND')->firstOrFail();
        $service = app(DealerAutoTierService::class);
        $service->setEnabled(true, $admin->id);

        $order = $this->order($account, $silver, '350.00');
        $this->payment($order, $admin, '80.00');
        $this->assertFalse($service->upgradeIfEligible($account->id)['changed']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);

        $this->payment($order, $admin, '40.00');
        $this->assertTrue($service->upgradeIfEligible($account->id)['changed']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);

        $this->payment($order, $admin, '200.00');
        $this->assertTrue($service->upgradeIfEligible($account->id)['changed']);
        $this->assertSame($diamond->id, $account->fresh()->current_tier_id);

        $other = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $this->payment($this->order($other, $silver, '350.00'), $admin, '350.00');
        $this->assertTrue($service->upgradeIfEligible($other->id)['changed']);
        $this->assertSame($diamond->id, $other->fresh()->current_tier_id);
        $this->assertDatabaseHas('dealer_tier_histories', [
            'dealer_account_id' => $other->id,
            'previous_tier_id' => $silver->id,
            'new_tier_id' => $diamond->id,
            'source' => 'automatic_upgrade',
        ]);

        $refund = Refund::factory()->forOrder($order)->create(['amount' => '300.00',
            'processed_by_user_id' => $admin->id]);
        $allocations = $order->paymentAllocations()->orderBy('id')->get();
        foreach (['80.00', '40.00', '180.00'] as $index => $amount) {
            $refund->allocations()->create(['payment_allocation_id' => $allocations[$index]->id,
                'amount' => $amount]);
        }
        $refund->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertFalse($service->upgradeIfEligible($account->id)['changed']);
        $this->assertSame($diamond->id, $account->fresh()->current_tier_id);
    }

    public function test_realtime_upgrade_respects_disabled_policy_unpaid_cancelled_and_override(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(12, 0));
        [$account, $silver, $gold, $admin] = $this->fixture();
        $service = app(DealerAutoTierService::class);
        $paid = $this->order($account, $silver, '150.00');
        $this->payment($paid, $admin, '150.00');
        $this->assertFalse($service->upgradeIfEligible($account->id)['changed']);
        $service->setEnabled(true, $admin->id);

        $other = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $this->payment($this->order($other, $silver, '150.00'), $admin, '150.00', 'pending');
        $cancelled = $this->order($other, $silver, '350.00');
        $this->payment($cancelled, $admin, '350.00');
        $cancelled->update(['order_status' => 'cancelled']);
        $this->assertFalse($service->upgradeIfEligible($other->id)['changed']);

        DealerTierOverride::factory()->create([
            'dealer_account_id' => $account->id,
            'tier_id' => $gold->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'created_by' => $admin->id,
        ]);
        $this->assertFalse($service->upgradeIfEligible($account->id)['changed']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
    }

    public function test_next_month_end_uses_calendar_month_lengths(): void
    {
        $service = app(DealerAutoTierService::class);
        foreach ([
            ['2026-02-28 23:55:00', '2026-03-31'],
            ['2028-02-29 23:55:00', '2028-03-31'],
            ['2026-09-30 23:55:00', '2026-10-31'],
            ['2026-10-31 23:55:00', '2026-11-30'],
        ] as [$date, $expected]) {
            $this->travelTo(CarbonImmutable::parse($date, 'Asia/Ho_Chi_Minh'));
            $this->assertSame($expected, $service->policy()['next_evaluation_at']);
        }
    }

    public function test_policy_preview_upgrade_refund_downgrade_and_idempotency(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
        [$account, $silver, $gold, $admin] = $this->fixture();
        $service = app(DealerAutoTierService::class);
        $order = $this->order($account, $silver);
        $payment = $this->payment($order, $admin, '150.00');

        $this->assertSame('disabled', $service->evaluate($account->id)['reason']);
        $this->assertTrue($service->evaluate($account->id)['would_change']);
        $this->assertSame('upgrade', $service->evaluate($account->id)['direction']);
        $this->assertSame('missing', $service->evaluate($account->id)['history_status']);
        $this->assertSame('150.00', $service->evaluate($account->id)['net_revenue']);
        $service->setEnabled(true, $admin->id);
        $this->assertSame('change_required', $service->evaluate($account->id)['reason']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->artisan('dealers:reconcile-auto-tiers', ['--dealer' => $account->id])->assertExitCode(0);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertSame('not_month_end', $service->evaluate($account->id, true)['reason']);
        $this->artisan('dealers:reconcile-auto-tiers', ['--apply' => true, '--dealer' => $account->id])->assertExitCode(0);
        $this->assertDatabaseCount('dealer_tier_evaluations', 0);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 55));
        $this->assertTrue($service->evaluate($account->id, true)['changed']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertFalse($service->evaluate($account->id, true)['changed']);

        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $refund = Refund::factory()->forOrder($order)->create(['amount' => '80.00',
            'processed_by_user_id' => $admin->id]);
        $refund->allocations()->create(['payment_allocation_id' => $payment->allocations()->firstOrFail()->id,
            'amount' => '80.00']);
        $refund->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame('downgrade', $service->evaluate($account->id)['direction']);
        $this->assertSame('not_month_end', $service->evaluate($account->id, true)['reason']);
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(23, 55));
        $this->assertTrue($service->evaluate($account->id, true)['changed']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertSame('70.00', $service->evaluate($account->id)['net_revenue']);
        $this->assertSame('30.00', $service->evaluate($account->id)['remaining_to_next']);
        $this->assertSame('consistent', $service->evaluate($account->id)['history_status']);
        $this->assertDatabaseCount('dealer_tier_histories', 2);
        $this->assertDatabaseCount('dealer_tier_evaluations', 2);
        $this->assertDatabaseHas('dealer_tier_histories', ['dealer_account_id' => $account->id,
            'source' => 'automatic_monthly_evaluation', 'net_revenue_snapshot' => '70.00', 'evaluation_period' => '2026-10']);
        $this->assertSame($silver->id, $order->fresh()->effective_tier_id_snapshot);
    }

    public function test_invalid_rules_fail_closed_and_dealer_scope_is_enforced(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dealer-tiers/auto-policy')->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson('/api/admin/dealer-tiers/auto-policy', ['enabled' => true])->assertOk();
        $this->patchJson("/api/admin/dealer-tiers/{$gold->id}", ['revenue_threshold' => '50'])
            ->assertOk()->assertJsonPath('data.revenue_threshold', '50.00');
        $this->getJson("/api/admin/dealers/{$account->id}/auto-tier")->assertOk()->assertJsonPath('data.net_revenue', '0.00');

        $member = User::factory()->customer()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $member->id]);
        Sanctum::actingAs($member);
        $this->getJson("/api/dealer/accounts/{$account->id}/auto-tier")->assertOk();
        $other = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $this->getJson("/api/dealer/accounts/{$other->id}/auto-tier")->assertNotFound();
        $this->putJson('/api/admin/dealer-tiers/auto-policy', ['enabled' => false])->assertForbidden();
    }

    public function test_suspended_account_is_read_only(): void
    {
        [$account, $silver, , $admin] = $this->fixture();
        $this->payment($this->order($account, $silver), $admin, '150.00');
        $service = app(DealerAutoTierService::class);
        $service->setEnabled(true, $admin->id);
        $account->update(['status' => DealerAccount::STATUS_SUSPENDED]);
        $this->assertSame('account_inactive', $service->evaluate($account->id, true)['reason']);
        $this->assertDatabaseCount('dealer_tier_histories', 0);
    }

    public function test_duplicate_or_non_monotonic_thresholds_cannot_enable_automation(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        Sanctum::actingAs($admin);
        $gold->update(['revenue_threshold' => '0.00']);
        $this->putJson('/api/admin/dealer-tiers/auto-policy', ['enabled' => true])
            ->assertConflict()->assertJsonPath('code', 'AUTO_TIER_RULES_INVALID');
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('dealer_tier_histories', 0);
        $this->assertDatabaseHas('dealer_auto_tier_policies', ['id' => 1, 'enabled' => false]);
    }

    public function test_three_calendar_month_window_assigns_all_three_tiers_and_later_downgrades(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 30)->setTime(12, 0));
        [$silverAccount, $silver, $gold, $admin] = $this->fixture();
        $diamond = DealerTier::query()->where('code', 'DIAMOND')->firstOrFail();
        $gold->update(['revenue_threshold' => '10000000.00']);
        $diamond->update(['revenue_threshold' => '30000000.00']);
        $this->payment($this->order($silverAccount, $silver, '100000000.00'), $admin, '100000000.00');

        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 55));
        $this->payment($this->order($silverAccount, $silver, '7000000.00'), $admin, '7000000.00');
        $goldAccount = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $this->payment($this->order($goldAccount, $silver, '15000000.00'), $admin, '15000000.00');
        $diamondAccount = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $this->payment($this->order($diamondAccount, $silver, '35000000.00'), $admin, '35000000.00');
        $service = app(DealerAutoTierService::class);
        $service->setEnabled(true, $admin->id);

        $this->assertSame('7000000.00', $service->evaluate($silverAccount->id, true)['net_revenue']);
        $this->assertSame($silver->id, $silverAccount->fresh()->current_tier_id);
        $this->assertSame('unchanged', DB::table('dealer_tier_evaluations')->where('dealer_account_id', $silverAccount->id)->value('result'));
        $this->assertTrue($service->evaluate($goldAccount->id, true)['changed']);
        $this->assertSame($gold->id, $goldAccount->fresh()->current_tier_id);
        $this->assertTrue($service->evaluate($diamondAccount->id, true)['changed']);
        $this->assertSame($diamond->id, $diamondAccount->fresh()->current_tier_id);
        $this->assertSame('2026-07-01', $service->evaluate($diamondAccount->id)['revenue_period_start']);
        $this->assertSame('2026-09-30', $service->evaluate($diamondAccount->id)['revenue_period_end']);
        $this->assertSame('already_evaluated', $service->evaluate($diamondAccount->id, true)['reason']);
        $this->assertDatabaseCount('dealer_tier_evaluations', 3);

        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(23, 55));
        $this->assertSame($diamond->id, $diamondAccount->fresh()->current_tier_id);
        $this->travelTo(now()->setDate(2027, 1, 31)->setTime(23, 55));
        $this->assertTrue($service->evaluate($diamondAccount->id, true)['changed']);
        $this->assertSame($silver->id, $diamondAccount->fresh()->current_tier_id);
    }

    public function test_unpaid_and_cancelled_orders_do_not_count_and_active_override_is_preserved(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 55));
        [$account, $silver, $gold, $admin] = $this->fixture();
        $this->payment($this->order($account, $silver), $admin, '150.00', 'pending');
        $cancelled = $this->order($account, $silver);
        $this->payment($cancelled, $admin, '350.00');
        $cancelled->update(['order_status' => 'cancelled']);
        $this->assertSame('0.00', app(DealerNetRevenueService::class)->forAccount($account)['net']);

        $this->payment($this->order($account, $silver), $admin, '150.00');
        DealerTierOverride::factory()->create([
            'dealer_account_id' => $account->id,
            'tier_id' => $gold->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'created_by' => $admin->id,
        ]);
        $service = app(DealerAutoTierService::class);
        $service->setEnabled(true, $admin->id);
        $this->assertSame('override_active', $service->evaluate($account->id, true)['reason']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseHas('dealer_tier_evaluations', [
            'dealer_account_id' => $account->id, 'evaluation_period' => '2026-09', 'result' => 'skipped_override',
        ]);
        $this->assertDatabaseCount('dealer_tier_histories', 0);
    }

    public function test_completed_return_reduces_revenue_without_double_counting_linked_refund(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 55));
        [$account, $silver, , $admin] = $this->fixture();
        $order = $this->order($account, $silver);
        $payment = $this->payment($order, $admin, '150.00');
        $variant = ProductVariant::factory()->create();
        $item = $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_code_snapshot' => $variant->product->product_code,
            'product_name_snapshot' => $variant->product->name,
            'sku_snapshot' => $variant->sku,
            'variant_name_snapshot' => $variant->variant_name,
            'unit_code_snapshot' => $variant->unit->code,
            'unit_name_snapshot' => $variant->unit->name,
            'quantity' => '1', 'pricing_context_snapshot' => 'dealer',
            'price_resolution_fingerprint' => str_repeat('c', 64),
            'unit_price_snapshot' => '80.00', 'base_amount' => '80.00', 'line_total' => '80.00',
        ]);
        $return = SalesReturn::factory()->forOrder($order)->create(['processed_by_user_id' => $admin->id]);
        $return->items()->create([
            'sales_order_item_id' => $item->id, 'quantity' => '1', 'restock_quantity' => '1',
            'non_restock_quantity' => '0', 'unit_value_snapshot' => '80.00', 'return_value_snapshot' => '80.00',
        ]);
        $return->update(['status' => 'completed', 'completed_at' => now()]);
        $revenue = app(DealerNetRevenueService::class);
        $this->assertSame('70.00', $revenue->forAccount($account)['net']);

        $refund = Refund::factory()->forOrder($order)->create([
            'sales_return_id' => $return->id, 'amount' => '80.00', 'processed_by_user_id' => $admin->id,
        ]);
        $refund->allocations()->create([
            'payment_allocation_id' => $payment->allocations()->firstOrFail()->id, 'amount' => '80.00',
        ]);
        $refund->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame('70.00', $revenue->forAccount($account)['net']);
    }

    public function test_month_end_refund_downgrades_diamond_to_gold_and_disabled_policy_preserves_tier(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 55));
        [$account, $silver, , $admin] = $this->fixture();
        $diamond = DealerTier::query()->where('code', 'DIAMOND')->firstOrFail();
        $gold = DealerTier::query()->where('code', 'GOLD')->firstOrFail();
        $order = $this->order($account, $silver, '350.00');
        $payment = $this->payment($order, $admin, '350.00');
        $service = app(DealerAutoTierService::class);
        $this->assertSame('disabled', $service->evaluate($account->id, true)['reason']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('dealer_tier_evaluations', 0);

        $service->setEnabled(true, $admin->id);
        $this->assertTrue($service->evaluate($account->id, true)['changed']);
        $this->assertSame($diamond->id, $account->fresh()->current_tier_id);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $refund = Refund::factory()->forOrder($order)->create([
            'amount' => '150.00', 'processed_by_user_id' => $admin->id,
        ]);
        $refund->allocations()->create([
            'payment_allocation_id' => $payment->allocations()->firstOrFail()->id, 'amount' => '150.00',
        ]);
        $refund->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame($diamond->id, $account->fresh()->current_tier_id);
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(23, 55));
        $this->assertSame('200.00', $service->evaluate($account->id, true)['net_revenue']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);

        $service->setEnabled(false, $admin->id);
        $this->travelTo(now()->setDate(2027, 1, 31)->setTime(23, 55));
        $this->assertSame('disabled', $service->evaluate($account->id, true)['reason']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('dealer_tier_evaluations', 2);
    }
}
