<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerApplication;
use App\Models\User;
use Database\Seeders\DealerTierPresetSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RetailBladeDealerApplicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, string> */
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

    public function test_guest_and_admin_cannot_use_customer_application_routes(): void
    {
        $application = DealerApplication::factory()->create();

        $this->get('/dealer/apply')->assertRedirect('/login');
        $this->post('/dealer/apply', $this->applicationBody())->assertRedirect('/login');
        $this->get('/dealer/application')->assertRedirect('/login');
        $this->get('/dealer/application/'.$application->id)->assertRedirect('/login');

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/dealer/apply')->assertForbidden();
        $this->post('/dealer/apply', $this->applicationBody())->assertForbidden();
        $this->get('/dealer/application/'.$application->id)->assertForbidden();
        $this->assertDatabaseCount('dealer_applications', 1);
    }

    public function test_customer_form_has_existing_fields_navigation_and_csrf(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->customer()->create());

        $this->get('/dealer/apply')->assertOk()->assertViewIs('retail.dealer-applications.create')
            ->assertSee('name="_token"', false)->assertSee('name="company_name"', false)
            ->assertSee('name="business_address_line1"', false)->assertSee('name="estimated_monthly_purchase"', false)
            ->assertSee('Gửi đơn đăng ký')->assertSee('Đại lý Junie');
        $this->get('/dealer/application')->assertRedirect(route('retail.web.dealer-applications.create'));
    }

    public function test_valid_submission_reaches_admin_without_premature_account(): void
    {
        $this->withoutVite();
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);

        $this->post('/dealer/apply', $this->applicationBody())->assertRedirect()->assertSessionHas('success');

        $application = DealerApplication::query()->sole();
        $this->assertSame($customer->id, $application->user_id);
        $this->assertSame('pending', $application->status);
        $this->assertSame('Junie Beauty', $application->company_name);
        $this->assertSame('lan@example.com', $application->email);
        $this->assertSame('+84901234567', $application->phone);
        $this->assertDatabaseCount('dealer_accounts', 0);
        $this->assertDatabaseCount('dealer_account_users', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'DealerApplication', 'target_id' => $application->id, 'action' => 'CREATE']);
        $this->get('/dealer/application')->assertOk()->assertSee('Chờ duyệt')->assertSee('Junie Beauty');

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/dealer-applications')->assertOk()->assertSee('Junie Beauty');
        $this->get('/admin/dealer-applications/'.$application->id)->assertOk()->assertSee('lan@example.com');
    }

    public function test_validation_preserves_old_input_and_rejects_internal_identity_fields(): void
    {
        $this->actingAs(User::factory()->customer()->create());

        $this->from('/dealer/apply')->post('/dealer/apply', [
            'company_name' => 'Junie Sample', 'phone' => 'invalid', 'status' => 'approved',
            'dealer_account_id' => 77, 'user_id' => 99,
        ])->assertRedirect('/dealer/apply')->assertSessionHasErrors(['contact_name', 'email', 'phone',
            'business_address_line1', 'city', 'province', 'country', 'user_id'])
            ->assertSessionHasInput('company_name', 'Junie Sample');

        $this->assertDatabaseCount('dealer_applications', 0);
        $this->assertDatabaseCount('dealer_accounts', 0);
    }

    public function test_valid_fields_cannot_set_approval_or_account_from_extra_input(): void
    {
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);

        $this->post('/dealer/apply', [...$this->applicationBody(),
            'status' => 'approved', 'reviewed_by' => 999, 'dealer_account_id' => 999,
        ])->assertRedirect();

        $application = DealerApplication::query()->sole();
        $this->assertSame($customer->id, $application->user_id);
        $this->assertSame('pending', $application->status);
        $this->assertNull($application->reviewed_by);
        $this->assertDatabaseCount('dealer_accounts', 0);
    }

    public function test_pending_duplicate_returns_form_error_without_creating_another_record(): void
    {
        $this->actingAs(User::factory()->customer()->create());
        $this->post('/dealer/apply', $this->applicationBody())->assertRedirect();

        $this->from('/dealer/apply')->post('/dealer/apply', $this->applicationBody())
            ->assertRedirect('/dealer/apply')->assertSessionHasErrors('application');

        $this->assertDatabaseCount('dealer_applications', 1);
    }

    public function test_detail_is_owner_scoped_and_escapes_submitted_content(): void
    {
        $this->withoutVite();
        $owner = User::factory()->customer()->create();
        $application = DealerApplication::factory()->create(['user_id' => $owner->id,
            'company_name' => '<script>alert(1)</script>', 'note' => '<img src=x onerror=alert(1)>']);
        $this->actingAs($owner);

        $this->get('/dealer/application/'.$application->id)->assertOk()->assertViewIs('retail.dealer-applications.show')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertDontSee('Người xét duyệt');
        $this->actingAs(User::factory()->customer()->create())
            ->get('/dealer/application/'.$application->id)->assertNotFound();
        $this->get('/dealer/application')->assertRedirect(route('retail.web.dealer-applications.create'));
    }

    public function test_rejection_allows_reapplication_but_pending_does_not_grant_dealer_access(): void
    {
        $this->withoutVite();
        $customer = User::factory()->customer()->create();
        $application = DealerApplication::factory()->create(['user_id' => $customer->id,
            'status' => DealerApplication::STATUS_REJECTED, 'rejection_reason' => 'Thiếu thông tin cơ sở']);
        $this->actingAs($customer);

        $this->get('/dealer/apply')->assertOk()->assertSee('Thiếu thông tin cơ sở')->assertSee('Gửi đơn đăng ký');
        $this->post('/dealer/apply', $this->applicationBody())->assertRedirect();

        $this->assertDatabaseCount('dealer_applications', 2);
        $this->assertSame(DealerApplication::STATUS_PENDING, $customer->dealerApplications()->latest('id')->firstOrFail()->status);
        $this->get('/dealer/application')->assertOk()->assertSee('Chờ duyệt')->assertDontSee('Thiếu thông tin cơ sở');
        $this->get('/dealer/orders')->assertForbidden();
        $this->assertSame(DealerApplication::STATUS_REJECTED, $application->fresh()->status);
    }

    public function test_admin_approval_changes_customer_status_and_grants_dealer_access_once(): void
    {
        $this->withoutVite();
        $this->seed(DealerTierPresetSeeder::class);
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);
        $this->post('/dealer/apply', $this->applicationBody())->assertRedirect();
        $application = DealerApplication::query()->sole();
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/dealer-applications/'.$application->id.'/approve')
            ->assertRedirect(route('admin.web.dealer-applications.show', $application));

        $this->actingAs($customer);
        $this->get('/dealer/application/'.$application->id)->assertOk()->assertSee('Đã duyệt')
            ->assertSee('Vào khu vực đại lý');
        $this->get('/dealer/orders')->assertOk();
        $this->get('/dealer/apply')->assertOk()->assertDontSee('Gửi đơn đăng ký');
        $this->from('/dealer/apply')->post('/dealer/apply', $this->applicationBody())
            ->assertSessionHasErrors('application');
        $this->assertDatabaseCount('dealer_accounts', 1);
        $this->assertDatabaseCount('dealer_account_users', 1);
        $this->assertSame(User::ROLE_CUSTOMER, $customer->fresh()->role);
    }

    public function test_admin_rejection_keeps_dealer_access_closed_and_shows_reason_to_owner(): void
    {
        $this->withoutVite();
        $customer = User::factory()->customer()->create();
        $application = DealerApplication::factory()->create(['user_id' => $customer->id]);
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/dealer-applications/'.$application->id.'/reject',
            ['rejection_reason' => 'Thông tin cơ sở chưa đầy đủ'])->assertRedirect();

        $this->actingAs($customer);
        $this->get('/dealer/application/'.$application->id)->assertOk()->assertSee('Đã từ chối')
            ->assertSee('Thông tin cơ sở chưa đầy đủ');
        $this->get('/dealer/orders')->assertForbidden();
        $this->assertDatabaseCount('dealer_accounts', 0);
    }

    public function test_existing_active_membership_blocks_new_application_even_if_account_is_suspended(): void
    {
        $this->withoutVite();
        $customer = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create(['status' => DealerAccount::STATUS_SUSPENDED]);
        DealerAccountUser::factory()->create(['user_id' => $customer->id,
            'dealer_account_id' => $account->id]);
        $this->actingAs($customer);

        $this->get('/dealer/apply')->assertOk()->assertDontSee('Gửi đơn đăng ký')
            ->assertDontSee('Vào khu vực đại lý');
        $this->from('/dealer/apply')->post('/dealer/apply', $this->applicationBody())
            ->assertSessionHasErrors('application');
        $this->get('/dealer/orders')->assertForbidden();
        $this->assertSame(0, $customer->dealerApplications()->count());
    }

    public function test_login_returns_to_application_without_membership_but_not_to_dealer_portal(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $customer = User::factory()->customer()->create(['password' => 'secret-password']);
        $credentials = ['email' => $customer->email, 'password' => 'secret-password'];

        $this->get('/dealer/apply')->assertRedirect('/login');
        $this->post('/login', [...$credentials, 'return_to' => '/dealer/apply'])
            ->assertRedirect('/dealer/apply');
        $this->post('/logout');
        $this->post('/login', [...$credentials, 'return_to' => '/dealer/application/123'])
            ->assertRedirect('/dealer/application/123');
        $this->post('/logout');
        $this->post('/login', [...$credentials, 'return_to' => '/dealer/orders'])
            ->assertRedirect(route('retail.web.shop.index'));
        $this->post('/logout');
        $this->post('/login', [...$credentials, 'return_to' => 'https://evil.example/admin'])
            ->assertRedirect(route('retail.web.shop.index'));
    }
}
