<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerApplication;
use App\Models\DealerTier;
use App\Models\User;
use App\Services\DealerTierService;
use Carbon\CarbonImmutable;
use Database\Seeders\DealerTierPresetSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerTierFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_fixed_tier_master_keeps_silver_initial_and_codes_immutable(): void
    {
        $this->seed(DealerTierPresetSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $silver = DealerTier::query()->where('code', 'SILVER')->firstOrFail();
        $this->postJson('/api/admin/dealer-tiers', ['code' => 'EXTRA', 'name' => 'Extra'])->assertStatus(405);
        $this->getJson('/api/admin/dealer-tiers')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.code', 'SILVER')->assertJsonPath('data.1.code', 'GOLD')
            ->assertJsonPath('data.2.code', 'DIAMOND');
        $this->patchJson("/api/admin/dealer-tiers/{$silver->id}", ['code' => 'CHANGED'])->assertUnprocessable();
        $this->patchJson("/api/admin/dealer-tiers/{$silver->id}", ['is_default_initial' => false])->assertUnprocessable();
        $this->patchJson("/api/admin/dealer-tiers/{$silver->id}", ['status' => 'inactive'])->assertUnprocessable();
        $this->assertSame($silver->id, app(DealerTierService::class)->defaultInitial()->id);
        if (DB::getDriverName() === 'mysql') {
            $this->expectException(QueryException::class);
            DB::table('dealer_tiers')->where('id', $silver->id)->update(['code' => 'CHANGED']);
        }
    }

    public function test_missing_initial_tier_blocks_approval_without_partial_dealer(): void
    {
        $application = DealerApplication::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_INITIAL_TIER_NOT_CONFIGURED');
        $this->assertDatabaseCount('dealer_accounts', 0);
        $this->assertDatabaseCount('dealer_account_users', 0);
        $this->assertDatabaseCount('dealer_tier_histories', 0);
        $this->assertDatabaseHas('dealer_applications', ['id' => $application->id, 'status' => 'pending']);

        $tier = DealerTier::factory()->create(['is_default_initial' => true]);
        $response = $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")->assertOk();
        $accountId = $response->json('data.approved_account.id');
        $this->assertDatabaseHas('dealer_accounts', ['id' => $accountId, 'current_tier_id' => $tier->id]);
        $this->assertDatabaseHas('dealer_tier_histories', ['dealer_account_id' => $accountId, 'new_tier_id' => $tier->id, 'source' => 'initial_assignment']);
        $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")->assertOk();
        $this->assertDatabaseCount('dealer_tier_histories', 1);
    }

    public function test_backfill_dry_run_then_assignment_is_idempotent(): void
    {
        $tier = DealerTier::factory()->create(['is_default_initial' => true]);
        $account = DealerAccount::factory()->create();
        $this->artisan('dealers:assign-initial-tier', ['--dry-run' => true, '--dealer' => $account->id, '--no-interaction' => true])
            ->expectsOutput('Need assignment: 1')->assertExitCode(0);
        $this->assertNull($account->refresh()->current_tier_id);
        $this->artisan('dealers:assign-initial-tier', ['--dealer' => $account->id, '--no-interaction' => true])->assertExitCode(0);
        $this->artisan('dealers:assign-initial-tier', ['--dealer' => $account->id, '--no-interaction' => true])->assertExitCode(0);
        $this->assertSame($tier->id, $account->refresh()->current_tier_id);
        $this->assertDatabaseHas('dealer_tier_histories', ['dealer_account_id' => $account->id, 'new_tier_id' => $tier->id, 'source' => 'migration']);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
    }

    public function test_dealer_can_read_assignment_and_next_month_expiry_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-31 10:00:00', 'UTC'));
        $tier = DealerTier::factory()->create(['is_default_initial' => true]);
        $owner = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $owner->id]);
        app(DealerTierService::class)->assignInitial($account);

        $account->refresh();
        $this->assertSame('2026-01-31', $account->tier_assigned_at->toDateString());
        $this->assertSame('2026-02-28', $account->tier_expires_at->toDateString());
        $this->assertDatabaseHas('dealer_tier_histories', [
            'dealer_account_id' => $account->id,
            'new_tier_id' => $tier->id,
            'expires_at' => $account->tier_expires_at->format('Y-m-d H:i:s'),
        ]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/dealer/accounts/{$account->id}/tier")
            ->assertOk()
            ->assertJsonPath('data.effective_tier.id', $tier->id)
            ->assertJsonPath('data.effective_at', $account->tier_assigned_at->toIso8601String())
            ->assertJsonPath('data.expires_at', $account->tier_expires_at->toIso8601String());
        $this->travelBack();
    }

    public function test_manual_change_requires_reason_is_idempotent_and_history_is_immutable(): void
    {
        $initial = DealerTier::factory()->create(['is_default_initial' => true]);
        $target = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        Sanctum::actingAs($admin);
        $operationKey = (string) Str::uuid();
        $url = "/api/admin/dealers/{$account->id}/tier/change";
        $this->postJson($url, ['tier_id' => $target->id, 'operation_key' => $operationKey])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $body = ['tier_id' => $target->id, 'reason' => 'Commercial agreement', 'operation_key' => $operationKey];
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.previous_tier.id', $initial->id)->assertJsonPath('data.new_tier.id', $target->id);
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, [...$body, 'reason' => 'Different'])->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_OPERATION_CONFLICT');
        $this->postJson($url, [...$body, 'operation_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_ALREADY_CURRENT');
        $this->getJson("/api/admin/dealers/{$account->id}/tier-history")->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame($target->id, $account->refresh()->current_tier_id);
        $this->assertNotNull($account->tier_assigned_at);
        $this->assertSame($account->tier_assigned_at->copy()->addMonthNoOverflow()->toIso8601String(), $account->tier_expires_at->toIso8601String());
        $this->assertDatabaseCount('dealer_tier_histories', 2);
        if (DB::getDriverName() === 'mysql') {
            $this->expectException(QueryException::class);
            DB::table('dealer_tier_histories')->where('operation_key', $operationKey)->delete();
        }
    }

    public function test_inactive_tier_cannot_be_assigned_or_override_and_current_tier_cannot_be_inactivated(): void
    {
        $initial = DealerTier::factory()->create(['is_default_initial' => true]);
        $inactive = DealerTier::factory()->create(['status' => 'inactive']);
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/dealers/{$account->id}/tier/change", ['tier_id' => $inactive->id, 'reason' => 'Change', 'operation_key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_INACTIVE');
        $this->postJson("/api/admin/dealers/{$account->id}/tier-overrides", ['tier_id' => $inactive->id, 'starts_at' => now()->toIso8601String(), 'reason' => 'Exception'])
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_INACTIVE');
        $this->patchJson("/api/admin/dealer-tiers/{$initial->id}", ['status' => 'inactive'])
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_IN_USE');
        $this->assertSame('active', $initial->refresh()->status);
    }

    public function test_override_changes_effective_tier_without_changing_base_then_expires_or_cancels(): void
    {
        $initial = DealerTier::factory()->create(['is_default_initial' => true]);
        $target = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create();
        $admin = User::factory()->admin()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        Sanctum::actingAs($admin);
        $start = now()->subMinute();
        $end = now()->addHour();
        $url = "/api/admin/dealers/{$account->id}/tier-overrides";
        $overrideId = $this->postJson($url, ['tier_id' => $target->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'reason' => 'Seasonal exception'])
            ->assertCreated()->json('data.id');
        $this->getJson("/api/admin/dealers/{$account->id}/tier")->assertOk()->assertJsonPath('data.base_tier.id', $initial->id)->assertJsonPath('data.effective_tier.id', $target->id)->assertJsonPath('data.source', 'manual_override')
            ->assertJsonPath('data.expires_at', $end->toIso8601String());
        $this->postJson($url, ['tier_id' => $target->id, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'reason' => 'Overlap'])
            ->assertStatus(409)->assertJsonPath('code', 'DEALER_TIER_OVERRIDE_OVERLAP');
        $this->assertSame($initial->id, $account->refresh()->current_tier_id);
        $this->travelTo($end->copy()->addSecond());
        $this->getJson("/api/admin/dealers/{$account->id}/tier")->assertOk()->assertJsonPath('data.effective_tier.id', $initial->id);
        $this->travelBack();
        $this->postJson("/api/admin/dealers/{$account->id}/tier-overrides/{$overrideId}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/admin/dealers/{$account->id}/tier")->assertOk()->assertJsonPath('data.effective_tier.id', $initial->id);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
    }

    public function test_only_active_member_reads_own_tier_and_non_admin_cannot_manage_tiers(): void
    {
        $tier = DealerTier::factory()->create(['is_default_initial' => true]);
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $owner->id]);
        app(DealerTierService::class)->assignInitial($account);
        $url = "/api/dealer/accounts/{$account->id}/tier";
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($other);
        $this->getJson($url)->assertNotFound();
        $this->postJson("/api/admin/dealers/{$account->id}/tier/change", [])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->getJson($url)->assertOk()->assertJsonPath('data.effective_tier.id', $tier->id);
        $this->getJson('/api/retail/cart')->assertOk();
        $account->update(['status' => DealerAccount::STATUS_SUSPENDED]);
        $this->getJson($url)->assertNotFound();
        $this->assertSame($tier->id, $account->refresh()->current_tier_id);
    }

    public function test_dealer_tier_endpoint_reports_unassigned_tier_without_failing(): void
    {
        $user = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => null]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/dealer/accounts/{$account->id}/tier")
            ->assertOk()->assertJsonPath('data.effective_tier', null);
    }
}
