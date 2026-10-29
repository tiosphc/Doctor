<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerWalletTopUpRequest;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DealerWalletTopUpConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestingDatabase();
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertExitCode(0);
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

    private function process(string $code): Process
    {
        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '.$code],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'aesthetic_clinic_testing',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 30);
    }

    public function test_concurrent_paid_webhook_replay_creates_one_credit(): void
    {
        $account = DealerAccount::factory()->create();
        $topUp = DealerWalletTopUpRequest::factory()->create([
            'dealer_account_id' => $account->id,
            'provider_order_code' => 100000000123,
            'provider_payment_link_id' => 'link-123',
            'status' => 'pending',
        ]);
        $data = ['orderCode' => (int) $topUp->provider_order_code, 'amount' => 100000000, 'currency' => 'VND',
            'paymentLinkId' => 'link-123', 'reference' => 'BANK-123', 'code' => '00', 'desc' => 'Success'];
        ksort($data);
        $signature = hash_hmac('sha256', implode('&', array_map(fn ($key) => $key.'='.$data[$key], array_keys($data))), 'test-checksum');
        $payload = var_export(['code' => '00', 'success' => true, 'data' => $data, 'signature' => $signature], true);
        $blocker = $this->process('\Illuminate\Support\Facades\DB::transaction(function () { '
            .'\Illuminate\Support\Facades\DB::table("dealer_accounts")->where("id", '.(int) $account->id.')->lockForUpdate()->first(); '
            .'echo "locked"; flush(); usleep(1000000); });');
        $blocker->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($blocker->getOutput(), 'locked') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('locked', $blocker->getOutput(), $blocker->getErrorOutput());
        $code = 'config()->set("services.payos.client_id", "test-client"); '
            .'config()->set("services.payos.api_key", "test-api-key"); '
            .'config()->set("services.payos.checksum_key", "test-checksum"); '
            .'$app->make(\App\Services\DealerWalletTopUpService::class)->handleWebhook('.$payload.'); echo "ok";';
        $left = $this->process($code);
        $right = $this->process($code);
        $left->start();
        $right->start();
        $blocker->wait();
        $left->wait();
        $right->wait();
        $this->assertTrue($left->isSuccessful(), $left->getErrorOutput());
        $this->assertTrue($right->isSuccessful(), $right->getErrorOutput());
        $this->assertSame('ok', $left->getOutput());
        $this->assertSame('ok', $right->getOutput());
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '100000000.00']);
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
    }
}
