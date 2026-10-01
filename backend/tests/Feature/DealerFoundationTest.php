<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerApplication;
use App\Models\DealerTier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function applicationBody(): array
    {
        return [
            'company_name' => '  Junie   Beauty  ',
            'contact_name' => '  Lan   Nguyen ',
            'email' => '  LAN@example.com ',
            'phone' => '090 123 4567',
            'business_address_line1' => '12 Main Street',
            'city' => 'Ho Chi Minh City',
            'province' => 'Ho Chi Minh',
            'country' => 'VN',
        ];
    }

    public function test_customer_submits_and_reads_own_pending_application_without_losing_retail_access(): void
    {
        $user = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/dealer-applications', $this->applicationBody());
        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.company_name', 'Junie Beauty');
        $applicationId = $response->json('data.id');
        $this->assertDatabaseHas('dealer_applications', ['id' => $applicationId, 'user_id' => $user->id, 'email' => 'lan@example.com', 'phone' => '+84901234567']);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'user_id' => $user->id]);
        $this->assertDatabaseCount('dealer_accounts', 0);
        $this->getJson('/api/dealer-applications/my')->assertOk()->assertJsonPath('data.id', $applicationId);
        $this->getJson("/api/dealer-applications/{$applicationId}")->assertOk();
        $this->getJson('/api/retail/cart')->assertOk();
        $this->getJson('/api/retail/orders')->assertOk();
        $this->assertSame(User::ROLE_CUSTOMER, $user->refresh()->role);
    }

    public function test_application_auth_validation_duplicate_and_cross_user_access(): void
    {
        $this->postJson('/api/dealer-applications', $this->applicationBody())->assertUnauthorized();
        $applicant = User::factory()->customer()->create();
        Sanctum::actingAs($applicant);
        $this->postJson('/api/dealer-applications', [...$this->applicationBody(), 'user_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/dealer-applications', [...$this->applicationBody(), 'phone' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $application = $this->postJson('/api/dealer-applications', $this->applicationBody())->assertCreated()->json('data');
        $this->postJson('/api/dealer-applications', $this->applicationBody())->assertStatus(409)->assertJsonPath('code', 'DEALER_APPLICATION_PENDING');
        $this->assertDatabaseCount('dealer_applications', 1);

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson('/api/dealer-applications/'.$application['id'])->assertNotFound();
        $this->getJson('/api/dealer-applications/my')->assertNotFound();
        $this->postJson('/api/admin/dealer-applications/'.$application['id'].'/approve')->assertForbidden();
    }

    public function test_admin_approval_is_idempotent_creates_one_account_and_owner_membership(): void
    {
        $initialTier = DealerTier::factory()->create(['is_default_initial' => true]);
        $applicant = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $applicant->id]);
        $application = DealerApplication::factory()->create(['user_id' => $applicant->id, 'estimated_monthly_purchase' => null, 'tax_code' => null]);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dealer-applications?status=pending&search='.$application->company_name)
            ->assertOk()->assertJsonPath('meta.total', 1);
        $first = $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $accountId = $first->json('data.approved_account.id');
        $this->assertNotNull($accountId);
        $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")->assertOk()->assertJsonPath('data.approved_account.id', $accountId);
        $this->assertDatabaseCount('dealer_accounts', 1);
        $this->assertDatabaseCount('dealer_account_users', 1);
        $this->assertDatabaseHas('dealer_account_users', ['dealer_account_id' => $accountId, 'user_id' => $applicant->id, 'membership_role' => 'owner', 'status' => 'active']);
        $this->assertDatabaseHas('dealer_accounts', ['id' => $accountId, 'code' => 'DLR'.str_pad((string) $accountId, 8, '0', STR_PAD_LEFT), 'source_application_id' => $application->id]);
        $this->assertDatabaseHas('dealer_accounts', ['id' => $accountId, 'current_tier_id' => $initialTier->id]);
        $this->assertDatabaseHas('dealer_tier_histories', ['dealer_account_id' => $accountId, 'new_tier_id' => $initialTier->id, 'source' => 'initial_assignment']);
        $this->assertDatabaseCount('dealer_tier_histories', 1);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'user_id' => $applicant->id]);
        $this->assertSame(User::ROLE_CUSTOMER, $applicant->refresh()->role);
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::table('dealer_accounts')->where('id', $accountId)->update(['code' => 'OTHER']);
                $this->fail('Dealer code must be immutable.');
            } catch (QueryException) {
                $this->assertDatabaseHas('dealer_accounts', ['id' => $accountId, 'code' => 'DLR'.str_pad((string) $accountId, 8, '0', STR_PAD_LEFT)]);
            }
        }
    }

    public function test_rejection_requires_reason_and_does_not_create_account(): void
    {
        $application = DealerApplication::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/dealer-applications/{$application->id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->postJson("/api/admin/dealer-applications/{$application->id}/reject", ['rejection_reason' => 'Information incomplete'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Information incomplete');
        $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")->assertStatus(409);
        $this->assertDatabaseCount('dealer_accounts', 0);
        $this->assertDatabaseCount('dealer_account_users', 0);
        Sanctum::actingAs($application->user);
        $this->getJson('/api/retail/cart')->assertOk();
    }

    public function test_active_membership_and_account_are_both_required_for_dealer_context(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        $membership = DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $owner->id]);
        Sanctum::actingAs($owner);
        $this->getJson('/api/dealer/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.membership_role', 'owner');
        $this->getJson("/api/dealer/accounts/{$account->id}")->assertOk();
        Sanctum::actingAs($other);
        $this->getJson("/api/dealer/accounts/{$account->id}")->assertNotFound();
        Sanctum::actingAs($owner);
        $membership->update(['status' => 'suspended']);
        $this->getJson('/api/dealer/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/dealer/accounts/{$account->id}")->assertNotFound();
        $membership->update(['status' => 'active']);
        $account->update(['status' => 'suspended']);
        $this->getJson("/api/dealer/accounts/{$account->id}")->assertNotFound();
        $this->getJson('/api/retail/cart')->assertOk();
    }

    public function test_admin_edits_business_fields_and_controls_status_without_changing_code(): void
    {
        $account = DealerAccount::factory()->create();
        $code = $account->code;
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson("/api/admin/dealers/{$account->id}", ['legal_name' => 'Updated Business', 'phone' => '090 123 4567'])
            ->assertOk()->assertJsonPath('data.legal_name', 'Updated Business')->assertJsonPath('data.phone', '+84901234567');
        $this->patchJson("/api/admin/dealers/{$account->id}", ['code' => 'OTHER'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson("/api/admin/dealers/{$account->id}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/admin/dealers/{$account->id}/suspend")->assertStatus(409);
        $this->postJson("/api/admin/dealers/{$account->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/admin/dealers/{$account->id}/inactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertSame($code, $account->refresh()->code);
        $this->getJson('/api/admin/dealers')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/dealers/{$account->id}")->assertOk()->assertJsonCount(0, 'data.memberships');
    }

    public function test_rejected_user_can_apply_again_but_active_dealer_cannot(): void
    {
        $user = User::factory()->customer()->create();
        DealerApplication::factory()->create(['user_id' => $user->id, 'status' => 'rejected']);
        Sanctum::actingAs($user);
        $this->postJson('/api/dealer-applications', $this->applicationBody())->assertCreated();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $account->update(['status' => DealerAccount::STATUS_SUSPENDED]);
        DealerApplication::query()->where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'rejected']);
        $this->postJson('/api/dealer-applications', $this->applicationBody())->assertStatus(409)->assertJsonPath('code', 'DEALER_MEMBERSHIP_EXISTS');
    }

    public function test_non_admin_cannot_review_applications_or_manage_dealer_accounts(): void
    {
        $application = DealerApplication::factory()->create();
        $account = DealerAccount::factory()->create();

        $this->getJson('/api/admin/dealer-applications')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson('/api/admin/dealer-applications')->assertForbidden();
        $this->getJson("/api/admin/dealer-applications/{$application->id}")->assertForbidden();
        $this->postJson("/api/admin/dealer-applications/{$application->id}/approve")->assertForbidden();
        $this->postJson("/api/admin/dealer-applications/{$application->id}/reject", ['rejection_reason' => 'No'])->assertForbidden();
        $this->getJson('/api/admin/dealers')->assertForbidden();
        $this->getJson("/api/admin/dealers/{$account->id}")->assertForbidden();
        $this->patchJson("/api/admin/dealers/{$account->id}", ['legal_name' => 'Unauthorized'])->assertForbidden();
        $this->postJson("/api/admin/dealers/{$account->id}/suspend")->assertForbidden();
        $this->assertDatabaseHas('dealer_applications', ['id' => $application->id, 'status' => 'pending']);
        $this->assertDatabaseHas('dealer_accounts', ['id' => $account->id, 'status' => 'active']);
    }
}
