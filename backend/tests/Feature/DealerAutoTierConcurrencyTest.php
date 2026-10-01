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

    public function test_parallel_settled_payments_produce_one_upgrade(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $firstOrder = $this->order($account, $silver, '60.00');
        $secondOrder = $this->order($account, $silver, '60.00');
        $first = $this->pay($firstOrder->id, $admin->id, '60.00');
        $second = $this->pay($secondOrder->id, $admin->id, '60.00');
        $first->start();
        $second->start();
        $this->assertProcess($first);
        $this->assertProcess($second);

        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertSame('120.00', app(DealerAutoTierService::class)->evaluate($account->id)['net_revenue']);
        $this->assertTrue(app(DealerAutoTierService::class)->evaluate($account->id, true)['changed']);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
        $this->assertSame($silver->id, $firstOrder->fresh()->effective_tier_id_snapshot);
    }

    public function test_parallel_payment_and_refund_converge_on_net_revenue(): void
    {
        [$account, $silver, $gold, $admin] = $this->fixture();
        $oldOrder = $this->order($account, $silver, '100.00');
        app(PaymentService::class)->recordSettledPayment($oldOrder, [
            'operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_method' => 'cash',
        ], $admin->id);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $newOrder = $this->order($account, $silver, '40.00');
        $pay = $this->pay($newOrder->id, $admin->id, '40.00');
        $refund = $this->refund($oldOrder->id, $admin->id, '100.00');
        $pay->start();
        $refund->start();
        $this->assertProcess($pay);
        $this->assertProcess($refund);

        $this->assertSame('40.00', app(DealerAutoTierService::class)->evaluate($account->id)['net_revenue']);
        $this->assertSame($silver->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('dealer_tier_histories', 0);
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

    public function test_parallel_manual_and_automatic_changes_keep_a_valid_history_chain(): void
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
            .'$app->make(\App\Services\DealerTierService::class)->change($account, $tier, "Contract exception", "'.Str::uuid().'", $admin); echo "ok";');
        $auto->start();
        $manual->start();
        $this->assertProcess($auto);
        $this->assertProcess($manual);
        $history = $account->tierHistories()->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame($silver->id, $history[0]->previous_tier_id);
        $this->assertSame($history[0]->new_tier_id, $history[1]->previous_tier_id);
        $this->assertSame($history[1]->new_tier_id, $account->fresh()->current_tier_id);
        $this->assertContains($account->fresh()->current_tier_id, [$bronze->id, $gold->id]);
    }
}
