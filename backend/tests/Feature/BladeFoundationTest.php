<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\DealerWallet;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BladeFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_sees_blade_login_and_registration_forms(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()->assertViewIs('auth.login')->assertSee('name="_token"', false);
        $this->get('/register')->assertOk()->assertViewIs('auth.register')->assertSee('name="_token"', false);
    }

    public function test_guest_is_redirected_from_protected_blade_pages(): void
    {
        $this->get('/blade/admin')->assertRedirect('/login');
        $this->get('/blade/dealer')->assertRedirect('/login');
    }

    public function test_admin_can_log_in_to_blade_dashboard_with_regenerated_session(): void
    {
        $this->withoutVite();
        $user = User::factory()->admin()->create(['password' => 'password123']);
        $session = app('session')->driver();
        $session->start();
        $sessionIdBeforeLogin = $session->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionIdBeforeLogin, $session->getId());
        $this->get('/blade/admin')->assertOk()->assertViewIs('admin.preview')->assertSee('Giao diện quản trị Blade');
    }

    public function test_wrong_password_returns_form_error_without_authentication(): void
    {
        $user = User::factory()->admin()->create(['password' => 'password123']);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email'])
            ->assertSessionHasInput('email', $user->email);

        $this->assertGuest('web');
    }

    public function test_unconfigured_doctor_cannot_use_blade_login(): void
    {
        $user = User::factory()->doctor()->pendingPasswordSetup()->create(['password' => 'password123']);
        Doctor::factory()->for($user)->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_registration_creates_customer_and_redirects_to_blade_shop(): void
    {
        config()->set('app.frontend_url', 'https://frontend.example.test');

        $this->post('/register', [
            'name' => 'Customer One',
            'email' => 'customer@example.test',
            'phone' => '0901234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('retail.web.shop.index'));

        $user = User::query()->where('email', 'customer@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertModelExists(Customer::query()->whereBelongsTo($user)->sole());
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_registration_rejects_role_escalation_with_redirect_errors(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'Customer One',
            'email' => 'customer@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_ADMIN,
        ])->assertRedirect('/register')->assertSessionHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'customer@example.test']);
    }

    public function test_only_admin_can_open_admin_preview(): void
    {
        $this->actingAs(User::factory()->customer()->create());

        $this->get('/blade/admin')->assertForbidden();
    }

    public function test_only_customer_with_active_dealer_membership_can_open_dealer_preview(): void
    {
        $this->withoutVite();
        $customer = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create(['code' => 'GOLD', 'name' => 'Gold']);
        $account = DealerAccount::factory()->create(['legal_name' => 'Junie Dealer', 'current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['user_id' => $customer->id, 'dealer_account_id' => $account->id]);
        DealerWallet::factory()->create(['dealer_account_id' => $account->id, 'balance' => '1250000.00']);

        $this->actingAs($customer)->get('/blade/dealer')
            ->assertOk()->assertViewIs('dealer.preview')->assertSee('Junie Dealer')
            ->assertSee('Gold')->assertSee('1.250.000đ');

        $this->actingAs(User::factory()->customer()->create())->get('/blade/dealer')->assertForbidden();
    }

    public function test_suspended_dealer_account_cannot_open_dealer_preview(): void
    {
        $customer = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create(['status' => DealerAccount::STATUS_SUSPENDED]);
        DealerAccountUser::factory()->create(['user_id' => $customer->id, 'dealer_account_id' => $account->id]);

        $this->actingAs($customer)->get('/blade/dealer')->assertForbidden();
    }

    public function test_admin_cannot_open_customer_dealer_preview(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/blade/dealer')->assertForbidden();
    }

    public function test_logout_invalidates_blade_session(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $this->post('/logout')->assertRedirect('/login');

        Auth::forgetGuards();
        $this->assertGuest('web');
    }
}
