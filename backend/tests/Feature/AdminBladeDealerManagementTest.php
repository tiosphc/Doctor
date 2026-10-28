<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerApplication;
use App\Models\DealerTier;
use App\Models\User;
use App\Services\DealerTierService;
use Database\Seeders\DealerTierPresetSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeDealerManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_routes_require_admin_and_missing_records_are_not_found(): void
    {
        $this->withoutVite();
        foreach (['/admin/dealer-applications', '/admin/dealers', '/admin/dealer-tiers'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->customer()->create());
        $this->get('/admin/dealers')->assertForbidden();
        $application = DealerApplication::factory()->create();
        $this->post('/admin/dealer-applications/'.$application->id.'/approve')->assertForbidden();
        $tier = DealerTier::factory()->create();
        $this->patch('/admin/dealer-tiers/'.$tier->id, ['description' => 'No access'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/dealer-applications/999999')->assertNotFound();
        $this->get('/admin/dealers/999999')->assertNotFound();
        $this->patch('/admin/dealer-tiers/999999', ['description' => 'Missing'])->assertNotFound();
    }

    public function test_application_list_filters_paginates_and_detail_shows_review(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $target = DealerApplication::factory()->create(['company_name' => 'Junie Target', 'status' => 'rejected',
            'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'rejection_reason' => 'Missing documents']);
        DealerApplication::factory()->count(16)->create(['status' => 'pending']);
        $this->actingAs($admin);
        $this->get('/admin/dealer-applications?search=Junie%20Target&status=rejected')->assertOk()->assertSee('Junie Target');
        $this->get('/admin/dealer-applications?status=pending')->assertOk()->assertSee('Trang 1/2');
        $this->get('/admin/dealer-applications/'.$target->id)->assertOk()->assertSee('Missing documents')->assertSee($admin->name);
    }

    public function test_approval_replays_without_duplicate_account_membership_tier_or_credit(): void
    {
        $this->withoutVite();
        $this->seed(DealerTierPresetSeeder::class);
        $application = DealerApplication::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $url = '/admin/dealer-applications/'.$application->id.'/approve';
        $this->post($url)->assertRedirect(route('admin.web.dealer-applications.show', $application));
        $this->post($url)->assertRedirect(route('admin.web.dealer-applications.show', $application));
        $account = $application->fresh()->approvedAccount;
        $this->assertNotNull($account);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame('SILVER', $account->currentTier->code);
        $this->assertSame('0.00', $account->wallet->balance);
        $this->assertSame(User::ROLE_CUSTOMER, $application->user->role);
        $this->assertDatabaseCount('dealer_accounts', 1);
        $this->assertDatabaseCount('dealer_account_users', 1);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
        $this->assertDatabaseHas('dealer_account_users', ['dealer_account_id' => $account->id,
            'user_id' => $application->user_id, 'membership_role' => 'owner', 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'DealerApplication', 'target_id' => $application->id, 'action' => 'CONFIRM']);
    }

    public function test_invalid_approval_and_rejection_keep_application_and_account_state(): void
    {
        $this->withoutVite();
        $application = DealerApplication::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $url = '/admin/dealer-applications/'.$application->id;
        $this->from($url)->post($url.'/approve')->assertSessionHasErrors('operation');
        $this->from($url)->post($url.'/reject', ['rejection_reason' => 'No'])->assertSessionHasErrors('rejection_reason');
        $this->post($url.'/reject', ['rejection_reason' => 'Incomplete business information'])->assertRedirect();
        $this->from($url)->post($url.'/approve')->assertSessionHasErrors('operation');
        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertDatabaseCount('dealer_accounts', 0);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'DealerApplication', 'target_id' => $application->id, 'action' => 'DEACTIVATE']);
    }

    public function test_account_filter_detail_update_and_status_preserve_membership_and_wallet(): void
    {
        $this->withoutVite();
        $this->seed(DealerTierPresetSeeder::class);
        $account = DealerAccount::factory()->create(['legal_name' => 'Junie Dealer',
            'current_tier_id' => DealerTier::query()->where('code', 'GOLD')->value('id')]);
        $owner = User::factory()->customer()->create();
        $membership = DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $owner->id]);
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/dealers?search=Junie%20Dealer&status=active&tier_id='.$account->current_tier_id)
            ->assertOk()->assertSee('Junie Dealer')->assertSee($owner->name);
        $this->get('/admin/dealers/'.$account->id)->assertOk()->assertSee($owner->email)->assertSee('chỉ xem')->assertSee('Gold');
        $this->patch('/admin/dealers/'.$account->id, ['legal_name' => 'Revised Dealer', 'phone' => '090 123 4567'])->assertRedirect();
        $this->assertSame('+84901234567', $account->fresh()->phone);
        $this->from('/admin/dealers/'.$account->id)->patch('/admin/dealers/'.$account->id, ['code' => 'BAD'])->assertSessionHasErrors('code');
        $this->post('/admin/dealers/'.$account->id.'/suspend')->assertRedirect();
        $this->from('/admin/dealers/'.$account->id)->post('/admin/dealers/'.$account->id.'/suspend')->assertSessionHasErrors('operation');
        $this->post('/admin/dealers/'.$account->id.'/activate')->assertRedirect();
        $this->post('/admin/dealers/'.$account->id.'/inactivate')->assertRedirect();
        $this->assertSame('inactive', $account->fresh()->status);
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertDatabaseCount('dealer_account_users', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->assertGreaterThan(0, AuditLog::query()->where('target_type', 'DealerAccount')->where('target_id', $account->id)->count());
    }

    public function test_tier_presets_thresholds_and_auto_policy_follow_existing_contract(): void
    {
        $this->withoutVite();
        $this->seed(DealerTierPresetSeeder::class);
        $this->actingAs(User::factory()->admin()->create());
        $gold = DealerTier::query()->where('code', 'GOLD')->firstOrFail();
        $diamond = DealerTier::query()->where('code', 'DIAMOND')->firstOrFail();
        $this->get('/admin/dealer-tiers')->assertOk()->assertSee('Silver')->assertSee('Gold')->assertSee('Diamond');
        $this->from('/admin/dealer-tiers')->patch('/admin/dealer-tiers/'.$gold->id, ['name' => 'Changed'])->assertSessionHasErrors('name');
        $this->from('/admin/dealer-tiers')->patch('/admin/dealer-tiers/'.$gold->id, ['revenue_threshold' => '0'])->assertSessionHasErrors('revenue_threshold');
        $this->from('/admin/dealer-tiers')->put('/admin/dealer-tiers/auto-policy', ['enabled' => '1'])->assertSessionHasErrors('operation');
        $this->patch('/admin/dealer-tiers/'.$gold->id, ['revenue_threshold' => '1000000', 'description' => 'Gold level'])->assertRedirect();
        $this->patch('/admin/dealer-tiers/'.$diamond->id, ['revenue_threshold' => '2000000'])->assertRedirect();
        $this->put('/admin/dealer-tiers/auto-policy', ['enabled' => '1'])->assertRedirect();
        $this->assertSame('1000000.00', $gold->fresh()->revenue_threshold);
        $this->assertDatabaseHas('dealer_auto_tier_policies', ['id' => 1, 'enabled' => 1]);
        $this->assertDatabaseCount('dealer_tiers', 3);
    }

    public function test_manual_tier_change_override_and_replay_preserve_history(): void
    {
        $this->withoutVite();
        $this->seed(DealerTierPresetSeeder::class);
        $admin = User::factory()->admin()->create();
        $account = DealerAccount::factory()->create();
        app(DealerTierService::class)->assignInitial($account, $admin);
        $gold = DealerTier::query()->where('code', 'GOLD')->firstOrFail();
        $diamond = DealerTier::query()->where('code', 'DIAMOND')->firstOrFail();
        $this->actingAs($admin);
        $url = '/admin/dealers/'.$account->id.'/tier/change';
        $body = ['tier_id' => $gold->id, 'reason' => 'Commercial agreement', 'operation_key' => (string) Str::uuid()];
        $this->post($url, $body)->assertRedirect();
        $this->post($url, $body)->assertRedirect();
        $this->from('/admin/dealers/'.$account->id)->post($url, [...$body, 'reason' => 'Different'])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('dealer_tier_histories', 2);
        $overrideUrl = '/admin/dealers/'.$account->id.'/tier-overrides';
        $this->post($overrideUrl, ['tier_id' => $diamond->id, 'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(), 'reason' => 'Seasonal exception'])->assertRedirect();
        $override = $account->tierOverrides()->firstOrFail();
        $this->from('/admin/dealers/'.$account->id)->post($overrideUrl, ['tier_id' => $diamond->id,
            'starts_at' => now()->toDateTimeString(), 'reason' => 'Overlap'])->assertSessionHasErrors('operation');
        $this->post($overrideUrl.'/'.$override->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $override->fresh()->status);
        $this->assertSame($gold->id, $account->fresh()->current_tier_id);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
    }
}
