<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerAutoTierService;
use App\Services\DealerTierService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DealerAutoTierConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestingDatabase();
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 55));
    }

    protected function tearDown(): void
    {
        $this->assertTestingDatabase();
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function assertTestingDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'aesthetic_clinic_testing') {
            throw new RuntimeException('Concurrency test may only reset the dedicated MySQL testing database.');
        }
    }

    /** @return array{DealerAccount, DealerTier, DealerTier, User} */
    private function fixture(): array
    {
        $silver = DealerTier::factory()->create(['code' => 'SILVER', 'sort_order' => 1,
            'is_default_initial' => true, 'revenue_threshold' => '0.00']);
        $gold = DealerTier::factory()->create(['code' => 'GOLD', 'sort_order' => 2,
            'revenue_threshold' => '100.00']);
        DealerTier::factory()->create(['code' => 'DIAMOND', 'sort_order' => 3,
            'revenue_threshold' => '300.00']);
        $account = DealerAccount::factory()->create(['current_tier_id' => $silver->id]);
        $admin = User::factory()->admin()->create();
        app(DealerAutoTierService::class)->setEnabled(true, $admin->id);

        return [$account, $silver, $gold, $admin];
    }

    private function order(DealerAccount $account, DealerTier $tier, string $total = '100.00'): SalesOrder
    {
        $buyer = User::factory()->customer()->create();

        return SalesOrder::query()->create([
            'order_code' => 'ORD'.Str::upper(Str::random(20)),
            'creation_operation_key' => (string) Str::uuid(), 'creation_fingerprint' => str_repeat('a', 64),
            'sales_channel' => 'dealer', 'order_source' => 'quick_order',
            'buyer_user_id' => $buyer->id, 'dealer_account_id' => $account->id,
            'dealer_code_snapshot' => $account->code, 'dealer_name_snapshot' => $account->legal_name,
            'effective_tier_id_snapshot' => $tier->id, 'effective_tier_code_snapshot' => $tier->code,
            'effective_tier_name_snapshot' => $tier->name, 'tier_source_snapshot' => 'current',
            'warehouse_id' => Warehouse::factory()->create()->id, 'currency' => 'VND',
            'recipient_name' => 'Dealer', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'order_status' => 'confirmed', 'pricing_context_snapshot' => 'dealer',
            'subtotal' => $total, 'grand_total' => $total,
            'price_resolution_fingerprint' => str_repeat('b', 64), 'created_by' => $buyer->id,
        ]);
    }

    private function process(string $code): Process
    {
        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \Carbon\Carbon::setTestNow("2026-09-30 23:55:00"); '.$code],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'aesthetic_clinic_testing',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 45);
    }

    private function assertProcess(Process $process): void
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('ok', trim($process->getOutput()));
    }

    private function pay(int $orderId, int $adminId, string $amount): Process
    {
        return $this->process('$order=\App\Models\SalesOrder::findOrFail('.$orderId.'); '
            .'$app->make(\App\Services\PaymentService::class)->recordSettledPayment($order, '
            .'["operation_key"=>"'.Str::uuid().'","amount"=>"'.$amount.'","payment_method"=>"cash"], '.$adminId.'); echo "ok";');
    }

    private function refund(int $orderId, int $adminId, string $amount): Process
    {
        return $this->process('$order=\App\Models\SalesOrder::findOrFail('.$orderId.'); '
            .'$app->make(\App\Services\RefundService::class)->complete($order, '
            .'["operation_key"=>"'.Str::uuid().'","amount"=>"'.$amount.'","refund_method"=>"cash","reason"=>"other"], '.$adminId.'); echo "ok";');
    }

    /** @param array<int, Process> $processes */
    private function startAtDatabaseLock(array $processes, string $table, array $rowIds): void
    {
        DB::transaction(function () use ($processes, $table, $rowIds): void {
            DB::table($table)->whereIn('id', $rowIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($processes as $process) {
                $process->start();
            }

            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                $queries = DB::select('SHOW FULL PROCESSLIST');
                $waitingCount = collect($queries)->filter(fn (object $row): bool => $row->db === 'aesthetic_clinic_testing'
                    && $row->Command === 'Execute'
                    && str_contains(strtolower((string) $row->Info), $table)
                    && str_contains(strtolower((string) $row->Info), 'for update'))->count();
                if ($waitingCount >= count($processes)) {
                    return;
                }

                if (collect($processes)->contains(fn (Process $process): bool => ! $process->isRunning())) {
                    break;
                }

                usleep(10000);
            }

            $this->fail('Processes did not both wait for the '.$table.' row lock: '
                .implode(' | ', array_map(fn (Process $process): string => $process->getErrorOutput().$process->getOutput(), $processes)));
        });
    }

    public function test_parallel_settled_payments_produce_one_upgrade(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $firstOrder = $this->order($account, $silver, '60.00');
        $secondOrder = $this->order($account, $silver, '60.00');
        $first = $this->pay($firstOrder->id, $admin->id, '60.00');
        $second = $this->pay($secondOrder->id, $admin->id, '60.00');
        $this->startAtDatabaseLock([$first, $second], 'sales_orders', [$firstOrder->id, $secondOrder->id]);
        $this->assertProcess($first);
        $this->assertProcess($second);

        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertSame('120.00', app(DealerAutoTierService::class)->evaluate($account->id)['net_revenue']);
        $this->assertFalse(app(DealerAutoTierService::class)->evaluate($account->id, true)['changed']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseHas('dealer_tier_histories', [
            'dealer_account_id' => $account->id, 'previous_tier_id' => $silver->id,
            'new_tier_id' => $gold->id, 'source' => 'automatic_upgrade',
        ]);
        $this->assertSame(1, $account->tierHistories()->count());
        $this->assertDatabaseHas('dealer_tier_evaluations', [
            'dealer_account_id' => $account->id, 'evaluation_period' => '2026-09',
            'result' => 'unchanged', 'net_revenue' => '120.00',
        ]);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertSame($silver->id, $firstOrder->fresh()->effective_tier_id_snapshot);
    }

    public function test_parallel_payment_and_refund_converge_on_net_revenue(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $oldOrder = $this->order($account, $silver, '100.00');
        app(PaymentService::class)->recordSettledPayment($oldOrder, [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
        ], $admin->id);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $newOrder = $this->order($account, $silver, '40.00');
        $pay = $this->pay($newOrder->id, $admin->id, '40.00');
        $refund = $this->refund($oldOrder->id, $admin->id, '100.00');
        $this->startAtDatabaseLock([$pay, $refund], 'sales_orders', [$newOrder->id, $oldOrder->id]);
        $this->assertProcess($pay);
        $this->assertProcess($refund);

        $this->assertSame('40.00', app(DealerAutoTierService::class)->evaluate($account->id)['net_revenue']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $evaluation = app(DealerAutoTierService::class)->evaluate($account->id);
        $this->assertSame('40.00', $evaluation['net_revenue']);
        $this->assertSame('downgrade', $evaluation['direction']);
        $this->assertTrue(app(DealerAutoTierService::class)->evaluate($account->id, true)['changed']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $history = $account->tierHistories()->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame('automatic_upgrade', $history[0]->source);
        $this->assertSame($silver->id, $history[0]->previous_tier_id);
        $this->assertSame($gold->id, $history[0]->new_tier_id);
        $this->assertSame('automatic_monthly_evaluation', $history[1]->source);
        $this->assertSame($gold->id, $history[1]->previous_tier_id);
        $this->assertSame($silver->id, $history[1]->new_tier_id);
        $this->assertDatabaseHas('dealer_tier_evaluations', [
            'dealer_account_id' => $account->id, 'evaluation_period' => '2026-09',
            'result' => 'changed', 'net_revenue' => '40.00',
        ]);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame($silver->id, $newOrder->fresh()->effective_tier_id_snapshot);
    }

    public function test_parallel_auto_evaluations_do_not_duplicate_history(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $order = $this->order($account, $silver, '150.00');
        DB::table('dealer_auto_tier_policies')->where('id', 1)->update(['enabled' => false]);
        app(PaymentService::class)->recordSettledPayment($order, [
            'operation_key' => (string) Str::uuid(), 'amount' => '150.00', 'payment_method' => 'cash',
        ], $admin->id);
        DB::table('dealer_auto_tier_policies')->where('id', 1)->update(['enabled' => true]);
        $code = '$app->make(\App\Services\DealerAutoTierService::class)->evaluate('.$account->id.', true); echo "ok";';
        $first = $this->process($code);
        $second = $this->process($code);
        $first->start();
        $second->start();
        $this->assertProcess($first);
        $this->assertProcess($second);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
    }

    public function test_parallel_auto_evaluation_and_override_preserve_override(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $order = $this->order($account, $silver, '150.00');
        DB::table('dealer_auto_tier_policies')->where('id', 1)->update(['enabled' => false]);
        app(PaymentService::class)->recordSettledPayment($order, [
            'operation_key' => (string) Str::uuid(), 'amount' => '150.00', 'payment_method' => 'cash',
        ], $admin->id);
        DB::table('dealer_auto_tier_policies')->where('id', 1)->update(['enabled' => true]);
        $auto = $this->process('$app->make(\App\Services\DealerAutoTierService::class)->evaluate('.$account->id.', true); echo "ok";');
        $override = $this->process('$account=\App\Models\DealerAccount::findOrFail('.$account->id.'); '
            .'$tier=\App\Models\DealerTier::findOrFail('.$silver->id.'); $admin=\App\Models\User::findOrFail('.$admin->id.'); '
            .'$app->make(\App\Services\DealerTierService::class)->createOverride($account, $tier, '
            .'\Carbon\CarbonImmutable::now(), null, "Contract exception", $admin); echo "ok";');
        $auto->start();
        $override->start();
        $this->assertProcess($auto);
        $this->assertProcess($override);
        $this->assertContains($account->fresh()->current_tier_id, [$silver->id, $gold->id]);
        $this->assertSame('manual_override', app(DealerTierService::class)->resolve($account->fresh())['source']);
        $this->assertNotNull(app(DealerAutoTierService::class)->evaluate($account->id)['active_override']);
        $this->assertDatabaseCount('dealer_tier_overrides', 1);
    }

    public function test_parallel_manual_change_is_blocked_during_auto_evaluation_and_history_stays_valid(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $bronze = DealerTier::factory()->create(['code' => 'BRONZE', 'sort_order' => 3,
            'revenue_threshold' => null]);
        $order = $this->order($account, $silver, '150.00');
        DB::table('dealer_auto_tier_policies')->where('id', 1)->update(['enabled' => false]);
        app(PaymentService::class)->recordSettledPayment($order, [
            'operation_key' => (string) Str::uuid(), 'amount' => '150.00', 'payment_method' => 'cash',
        ], $admin->id);
        DB::table('dealer_auto_tier_policies')->where('id', 1)->update(['enabled' => true]);
        $auto = $this->process('$app->make(\App\Services\DealerAutoTierService::class)->evaluate('.$account->id.', true); echo "ok";');
        $manual = $this->process('$account=\App\Models\DealerAccount::findOrFail('.$account->id.'); '
            .'$tier=\App\Models\DealerTier::findOrFail('.$bronze->id.'); $admin=\App\Models\User::findOrFail('.$admin->id.'); '
            .'try { $app->make(\App\Services\DealerTierService::class)->change($account, $tier, "Contract exception", "'.Str::uuid().'", $admin); echo "unexpected_success"; } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) { echo $exception->getResponse()->getData(true)["code"]; }');
        $this->startAtDatabaseLock([$auto, $manual], 'dealer_accounts', [$account->id]);
        $this->assertProcess($auto);
        $manual->wait();
        $this->assertTrue($manual->isSuccessful(), $manual->getErrorOutput());
        $this->assertSame('AUTO_TIER_POLICY_ENABLED', trim($manual->getOutput()));
        $history = $account->tierHistories()->orderBy('id')->get();
        $this->assertCount(1, $history);
        $this->assertSame($silver->id, $history[0]->previous_tier_id);
        $this->assertSame($gold->id, $history[0]->new_tier_id);
        $this->assertSame('automatic_monthly_evaluation', $history[0]->source);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseHas('dealer_tier_evaluations', [
            'dealer_account_id' => $account->id, 'evaluation_period' => '2026-09', 'result' => 'changed',
        ]);
        $this->assertDatabaseCount('dealer_tier_overrides', 0);
    }
}
