<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_index_returns_only_customers_and_supports_search(): void
    {
        $mai = User::factory()->customer()->create([
            'name' => 'Nguyen Mai',
            'email' => 'mai@example.com',
        ]);
        Customer::factory()->withUser($mai)->create([
            'name' => 'Nguyen Mai',
            'primary_email' => 'mai@example.com',
            'normalized_email' => 'mai@example.com',
        ]);
        $linh = User::factory()->customer()->create([
            'name' => 'Tran Linh',
            'email' => 'linh@example.com',
        ]);
        Customer::factory()->withUser($linh)->create([
            'name' => 'Tran Linh',
            'primary_email' => 'linh@example.com',
            'normalized_email' => 'linh@example.com',
        ]);
        User::factory()->admin()->create(['name' => 'Mai Administrator']);

        $this->getJson('/api/admin/customers?search=mai')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'mai@example.com')
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_admin_can_view_a_customer_but_not_an_admin_as_customer(): void
    {
        $customer = User::factory()->customer()->create();
        $customerProfile = Customer::factory()->withUser($customer)->create();

        $this->getJson("/api/admin/customers/{$customerProfile->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customerProfile->id)
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonMissingPath('data.password');

        $this->getJson('/api/admin/customers/999999')->assertNotFound();
    }

    public function test_index_returns_completed_visit_count_and_latest_appointment_without_n_plus_one_fields(): void
    {
        $customer = User::factory()->customer()->create();
        $customerProfile = Customer::factory()->withUser($customer)->create();
        Appointment::factory()->for($customer)->for($customerProfile)->create([
            'status' => Appointment::STATUS_COMPLETED,
            'appointment_date' => '2026-09-18',
        ]);
        Appointment::factory()->for($customer)->for($customerProfile)->create([
            'status' => Appointment::STATUS_CANCELLED,
            'appointment_date' => '2026-09-20',
        ]);

        $this->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customerProfile->id)
            ->assertJsonPath('data.0.completed_visits', 1)
            ->assertJsonPath('data.0.last_appointment_date', '2026-09-20');
    }

    public function test_canonical_customer_without_user_is_returned_without_crashing(): void
    {
        $customer = Customer::factory()->guest()->create([
            'name' => 'Historic Guest',
            'primary_email' => null,
            'normalized_email' => null,
        ]);

        $this->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.user_id', null)
            ->assertJsonPath('data.0.role', User::ROLE_CUSTOMER);

        $this->getJson("/api/admin/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.loyalty.completed_visits', 0)
            ->assertJsonPath('data.statistics.reviews', 0);
    }
}
