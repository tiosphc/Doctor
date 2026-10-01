<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerApplication;
use App\Models\DealerTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DealerFoundationConcurrencyTest extends TestCase
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

    /** @return array<string, mixed> */
    private function processResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function applicationProcess(int $userId): Process
    {
        return $this->process('$user = \App\Models\User::findOrFail('.$userId.'); '
            .'try { $result = $app->make(\App\Services\DealerApplicationService::class)->submit($user, '
            .'["company_name"=>"Business", "contact_name"=>"Owner", "email"=>"owner@example.com", '
            .'"phone"=>"+84901234567", "business_address_line1"=>"Street", "city"=>"HCM", "province"=>"HCM", "country"=>"VN"]); '
            .'echo json_encode(["status"=>"ok", "id"=>$result->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { echo json_encode(["status"=>"conflict", "code"=>$e->getResponse()->getData(true)["code"]]); }');
    }

    private function reviewProcess(int $applicationId, int $adminId, string $action): Process
    {
        return $this->process('$application = \App\Models\DealerApplication::findOrFail('.$applicationId.'); '
            .'$admin = \App\Models\User::findOrFail('.$adminId.'); '
            .'try { $result = $app->make(\App\Services\DealerApplicationService::class)->'.$action.'($application, $admin'.($action === 'reject' ? ', "Incomplete information"' : '').'); '
            .'echo json_encode(["status"=>"ok", "application_status"=>$result->status, "account_id"=>$result->approvedAccount?->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { echo json_encode(["status"=>"conflict", "code"=>$e->getResponse()->getData(true)["code"]]); }');
    }

    private function suspendProcess(int $accountId, int $adminId): Process
    {
        return $this->process('$account = \App\Models\DealerAccount::findOrFail('.$accountId.'); '
            .'$admin = \App\Models\User::findOrFail('.$adminId.'); '
            .'try { $result = $app->make(\App\Services\DealerAccountService::class)->transition($account, "suspended", $admin); '
            .'echo json_encode(["status"=>"ok", "account_status"=>$result->status]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { echo json_encode(["status"=>"conflict", "code"=>$e->getResponse()->getData(true)["code"]]); }');
    }

    public function test_simultaneous_submissions_create_one_pending_application(): void
    {
        $user = User::factory()->customer()->create();
        $first = $this->applicationProcess($user->id);
        $second = $this->applicationProcess($user->id);
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('dealer_applications', 1);
        $this->assertDatabaseHas('dealer_applications', ['user_id' => $user->id, 'status' => 'pending']);
    }

    public function test_simultaneous_approvals_return_one_account_and_membership(): void
    {
        DealerTier::factory()->create(['is_default_initial' => true]);
        $application = DealerApplication::factory()->create();
        $admin = User::factory()->admin()->create();
        $first = $this->reviewProcess($application->id, $admin->id, 'approve');
        $second = $this->reviewProcess($application->id, $admin->id, 'approve');
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['account_id'], $results[1]['account_id']);
        $this->assertDatabaseCount('dealer_accounts', 1);
        $this->assertDatabaseCount('dealer_account_users', 1);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
        $this->assertDatabaseHas('dealer_applications', ['id' => $application->id, 'status' => 'approved']);
    }

    public function test_approve_reject_race_has_exactly_one_outcome(): void
    {
        DealerTier::factory()->create(['is_default_initial' => true]);
        $application = DealerApplication::factory()->create();
        $admin = User::factory()->admin()->create();
        $approve = $this->reviewProcess($application->id, $admin->id, 'approve');
        $reject = $this->reviewProcess($application->id, $admin->id, 'reject');
        $approve->start();
        $reject->start();
        $results = [$this->processResult($approve), $this->processResult($reject)];
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $status = $application->refresh()->status;
        $this->assertContains($status, ['approved', 'rejected']);
        $this->assertDatabaseCount('dealer_accounts', $status === 'approved' ? 1 : 0);
        $this->assertDatabaseCount('dealer_account_users', $status === 'approved' ? 1 : 0);
        $this->assertDatabaseCount('dealer_tier_histories', $status === 'approved' ? 1 : 0);
    }

    public function test_simultaneous_account_suspensions_commit_once(): void
    {
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        $first = $this->suspendProcess($account->id, $admin->id);
        $second = $this->suspendProcess($account->id, $admin->id);
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame('suspended', $account->refresh()->status);
        $this->assertSame('DEALER_STATUS_TRANSITION_INVALID', collect($results)->firstWhere('status', 'conflict')['code']);
    }
}
