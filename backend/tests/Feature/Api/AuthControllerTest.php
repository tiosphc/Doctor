<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_can_register_and_password_is_hashed(): void
    {
        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/register', [
            'name' => 'Customer One',
            'email' => 'customer.one@example.com',
            'phone' => '0901234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'customer.one@example.com')
            ->assertJsonPath('data.role', User::ROLE_CUSTOMER)
            ->assertJsonMissingPaths(['data.password', 'data.remember_token']);

        $user = User::query()->where('email', 'customer.one@example.com')->firstOrFail();

        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertAuthenticatedAs($user);
        $customer = Customer::query()->whereBelongsTo($user)->sole();
        $this->assertSame('Customer One', $customer->name);
        $this->assertSame('customer.one@example.com', $customer->normalized_email);
        $this->assertSame('+84901234567', $customer->normalized_phone);
        $this->assertNotNull($customer->customer_code);
    }

    public function test_register_rejects_role_escalation_attempt(): void
    {
        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/register', [
            'name' => 'Malicious Customer',
            'email' => 'malicious@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_ADMIN,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'malicious@example.com']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/register', [
            'name' => 'Another Customer',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->customer()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPaths(['data.password', 'data.remember_token']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_returns_401_for_incorrect_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'incorrect-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'The provided credentials are incorrect.']);
        $this->assertGuest();
    }

    public function test_pending_doctor_cannot_login_before_setting_password(): void
    {
        $user = User::factory()->doctor()->pendingPasswordSetup()->create([
            'email' => 'pending.doctor@example.test',
            'password' => 'password123',
        ]);
        Doctor::factory()->for($user)->create();

        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('requires_password_setup', true);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_retrieve_current_profile(): void
    {
        $user = User::factory()->customer()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', User::ROLE_CUSTOMER)
            ->assertJsonMissingPaths(['data.password', 'data.remember_token']);
    }

    public function test_guest_receives_401_from_current_user_endpoint(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_name_and_phone(): void
    {
        $user = User::factory()->customer()->create([
            'name' => 'Original Name',
            'phone' => '0900000000',
        ]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user', [
            'name' => 'Updated Name',
            'phone' => '0911111111',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.phone', '0911111111')
            ->assertJsonPath('data.email', $user->email);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'phone' => '0911111111',
        ]);
        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'name' => 'Updated Name',
            'primary_phone' => '0911111111',
            'normalized_phone' => '+84911111111',
        ]);
    }

    public function test_profile_update_rejects_email_tampering_without_changing_profile_data(): void
    {
        $user = User::factory()->customer()->create([
            'name' => 'Original Name',
            'phone' => '0900000000',
        ]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user', [
            'name' => 'Tampered Name',
            'phone' => '0911111111',
            'email' => 'attacker@example.com',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Original Name',
            'email' => $user->email,
            'phone' => '0900000000',
        ]);
    }

    public function test_guest_receives_401_when_updating_profile(): void
    {
        $this->patchJson('/api/user', [
            'name' => 'Updated Name',
            'phone' => '0911111111',
        ])->assertUnauthorized();
    }

    public function test_logout_invalidates_authenticated_session(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'password' => 'password123',
        ]);
        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'logout@example.com',
            'password' => 'password123',
        ])->assertOk();
        $sessionKey = Auth::guard('web')->getName();

        $response = $this->postJson('/api/logout');

        $response
            ->assertOk()
            ->assertExactJson(['message' => 'Logout successful.'])
            ->assertSessionMissing($sessionKey);
        Auth::forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_cors_preflight_allows_configured_spa_origin_with_credentials(): void
    {
        $response = $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
