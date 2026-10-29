<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerApplication;
use App\Models\DealerTier;
use App\Models\User;
use App\Services\DealerTierService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DealerTierConcurrencyTest extends TestCase
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

    private function assignment(int $accountId): Process
    {
        return $this->process('$account = \App\Models\DealerAccount::findOrFail('.$accountId.'); '
            .'try { $history = $app->make(\App\Services\DealerTierService::class)->assignInitial($account); '
            .'echo json_encode(["status"=>"ok", "id"=>$history->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { echo json_encode(["status"=>"conflict"]); }');
    }

    private function change(int $accountId, int $tierId, int $adminId, string $key): Process
    {
        return $this->process('$account = \App\Models\DealerAccount::findOrFail('.$accountId.'); '
            .'$tier = \App\Models\DealerTier::findOrFail('.$tierId.'); $admin = \App\Models\User::findOrFail('.$adminId.'); '
            .'try { $history = $app->make(\App\Services\DealerTierService::class)->change($account, $tier, "Commercial change", "'.$key.'", $admin); '
            .'echo json_encode(["status"=>"ok", "id"=>$history->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { echo json_encode(["status"=>"conflict"]); }');
    }

    private function override(int $accountId, int $tierId, int $adminId): Process
    {
        return $this->process('$account = \App\Models\DealerAccount::findOrFail('.$accountId.'); '
            .'$tier = \App\Models\DealerTier::findOrFail('.$tierId.'); $admin = \App\Models\User::findOrFail('.$adminId.'); '
            .'try { $item = $app->make(\App\Services\DealerTierService::class)->createOverride($account, $tier, \Carbon\CarbonImmutable::now(), \Carbon\CarbonImmutable::now()->addDay(), "Exception", $admin); '
            .'echo json_encode(["status"=>"ok", "id"=>$item->id]); } '
            .'catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { echo json_encode(["status"=>"conflict"]); }');
    }

    public function test_concurrent_initial_assignment_creates_one_history(): void
    {
        DealerTier::factory()->create(['is_default_initial' => true]);
        $account = DealerAccount::factory()->create();
        $first = $this->assignment($account->id);
        $second = $this->assignment($account->id);
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
    }

    public function test_concurrent_different_target_changes_form_a_consistent_history_chain(): void
    {
        $initial = DealerTier::factory()->create(['is_default_initial' => true]);
        $firstTier = DealerTier::factory()->create();
        $secondTier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        $first = $this->change($account->id, $firstTier->id, $admin->id, (string) Str::uuid());
        $second = $this->change($account->id, $secondTier->id, $admin->id, (string) Str::uuid());
        $first->start();
        $second->start();
        $this->assertSame(['ok', 'ok'], [$this->processResult($first)['status'], $this->processResult($second)['status']]);
        $history = $account->tierHistories()->orderBy('id')->get();
        $this->assertCount(3, $history);
        $this->assertSame($initial->id, $history[1]->previous_tier_id);
        $this->assertSame($history[1]->new_tier_id, $history[2]->previous_tier_id);
        $this->assertSame($history[2]->new_tier_id, $account->refresh()->current_tier_id);
    }

    public function test_concurrent_same_target_changes_commit_once(): void
    {
        DealerTier::factory()->create(['is_default_initial' => true]);
        $target = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        $first = $this->change($account->id, $target->id, $admin->id, (string) Str::uuid());
        $second = $this->change($account->id, $target->id, $admin->id, (string) Str::uuid());
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('dealer_tier_histories', 2);
    }

    public function test_concurrent_approval_retries_assign_one_initial_tier(): void
    {
        DealerTier::factory()->create(['is_default_initial' => true]);
        $application = DealerApplication::factory()->create();
        $admin = User::factory()->admin()->create();
        $code = '$application = \App\Models\DealerApplication::findOrFail('.$application->id.'); '
            .'$admin = \App\Models\User::findOrFail('.$admin->id.'); '
            .'$result = $app->make(\App\Services\DealerApplicationService::class)->approve($application, $admin); '
            .'echo json_encode(["account_id"=>$result->approvedAccount->id]);';
        $first = $this->process($code);
        $second = $this->process($code);
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame($results[0]['account_id'], $results[1]['account_id']);
        $this->assertDatabaseCount('dealer_accounts', 1);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
    }

    public function test_concurrent_overrides_cannot_overlap(): void
    {
        DealerTier::factory()->create(['is_default_initial' => true]);
        $target = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        $first = $this->override($account->id, $target->id, $admin->id);
        $second = $this->override($account->id, $target->id, $admin->id);
        $first->start();
        $second->start();
        $results = [$this->processResult($first), $this->processResult($second)];
        $this->assertSame(['conflict', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('dealer_tier_overrides', 1);
    }
}
