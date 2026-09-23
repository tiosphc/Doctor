<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_receives_real_operational_dashboard_aggregates(): void
    {
        $this->travelTo('2026-09-21 10:00:00');
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $secondCustomer = User::factory()->customer()->create();
        $doctor = Doctor::factory()->create(['name' => 'BS. An']);
        $secondDoctor = Doctor::factory()->create(['name' => 'BS. Bình']);
        $service = Service::factory()->create(['name' => 'Laser']);
        $secondService = Service::factory()->create(['name' => 'Botox']);

        $overdue = Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $checkedIn = Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '10:30:00',
            'end_time' => '11:00:00',
            'status' => Appointment::STATUS_CHECKED_IN,
        ]);
        Appointment::factory()->for($secondCustomer)->for($secondDoctor)->for($secondService)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => Appointment::STATUS_IN_PROGRESS,
        ]);
        $completed = Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '08:00:00',
            'end_time' => '08:30:00',
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        Appointment::factory()->for($secondCustomer)->for($secondDoctor)->for($secondService)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '12:00:00',
            'end_time' => '12:30:00',
            'status' => Appointment::STATUS_CANCELLED,
        ]);
        Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-20',
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-22',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Review::factory()->create([
            'appointment_id' => $completed->id,
            'user_id' => $customer->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'created_at' => '2026-09-21 09:30:00',
        ]);
        Voucher::factory()->create([
            'user_id' => $customer->id,
            'source_id' => $checkedIn->id,
            'expires_at' => now()->addDays(3),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard?period=7_days');

        $response
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Ho_Chi_Minh')
            ->assertJsonPath('overview.doctors', 2)
            ->assertJsonPath('overview.services', 2)
            ->assertJsonPath('overview.appointments', 7)
            ->assertJsonPath('overview.customers', 2)
            ->assertJsonPath('today.date', '2026-09-21')
            ->assertJsonPath('today.appointments', 5)
            ->assertJsonPath('today.waiting_checkin', 1)
            ->assertJsonPath('today.checked_in', 1)
            ->assertJsonPath('today.in_progress', 1)
            ->assertJsonPath('today.completed', 1)
            ->assertJsonPath('today.cancelled', 1)
            ->assertJsonPath('today_appointments.0.id', $checkedIn->id)
            ->assertJsonPath('today_appointments.0.customer_name', $customer->name)
            ->assertJsonPath('appointment_statistics.period', '7_days')
            ->assertJsonPath('appointment_statistics.from', '2026-09-15')
            ->assertJsonPath('appointment_statistics.to', '2026-09-21')
            ->assertJsonCount(7, 'appointment_statistics.items')
            ->assertJsonPath('appointment_statistics.items.5.total', 1)
            ->assertJsonPath('appointment_statistics.items.5.completed', 1)
            ->assertJsonPath('appointment_statistics.items.6.total', 5)
            ->assertJsonPath('appointment_statistics.items.6.completed', 1)
            ->assertJsonPath('appointment_statistics.items.6.cancelled', 1)
            ->assertJsonPath('doctor_today.0.name', 'BS. An')
            ->assertJsonPath('doctor_today.0.appointments', 3)
            ->assertJsonPath('popular_services.0.name', 'Laser')
            ->assertJsonPath('popular_services.0.appointments', 5)
            ->assertJsonPath('recent_activities.available', true)
            ->assertJsonCount(0, 'recent_activities.items')
            ->assertJsonPath('revenue', null)
            ->assertJsonFragment(['key' => 'overdue_checkin', 'count' => 1])
            ->assertJsonFragment(['key' => 'new_reviews', 'count' => 1])
            ->assertJsonFragment(['key' => 'expiring_vouchers', 'count' => 1]);

        $this->assertSame($overdue->id, $response->json('today_appointments.3.id'));
    }

    public function test_dashboard_returns_zero_and_empty_states_when_operational_data_is_absent(): void
    {
        $this->travelTo('2026-09-21 10:00:00');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('overview', [
                'doctors' => 0,
                'services' => 0,
                'appointments' => 0,
                'customers' => 0,
            ])
            ->assertJsonPath('today.appointments', 0)
            ->assertJsonCount(0, 'today_appointments')
            ->assertJsonCount(0, 'actions_required')
            ->assertJsonCount(0, 'doctor_today')
            ->assertJsonCount(0, 'popular_services');
    }

    public function test_dashboard_supports_thirty_day_and_current_month_statistics(): void
    {
        $this->travelTo('2026-09-21 10:00:00');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/dashboard?period=30_days')
            ->assertOk()
            ->assertJsonPath('appointment_statistics.from', '2026-08-23')
            ->assertJsonPath('appointment_statistics.to', '2026-09-21')
            ->assertJsonCount(30, 'appointment_statistics.items');

        $this->getJson('/api/admin/dashboard?period=month')
            ->assertOk()
            ->assertJsonPath('appointment_statistics.from', '2026-09-01')
            ->assertJsonPath('appointment_statistics.to', '2026-09-30')
            ->assertJsonCount(30, 'appointment_statistics.items');
    }

    public function test_today_statistics_use_the_configured_timezone_at_day_boundaries(): void
    {
        $this->travelTo('2026-09-21 00:00:00');
        Appointment::factory()->create(['appointment_date' => '2026-09-21']);
        Appointment::factory()->create(['appointment_date' => '2026-09-22']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('today.date', '2026-09-21')
            ->assertJsonPath('today.appointments', 1);

        $this->travelTo('2026-09-21 23:59:59');

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('today.date', '2026-09-21')
            ->assertJsonPath('today.appointments', 1);
    }

    public function test_dashboard_rejects_an_unknown_statistics_period(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/dashboard?period=quarter')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_dashboard_forbids_non_admin_roles(string $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public static function nonAdminRoles(): array
    {
        return [
            'customer' => [User::ROLE_CUSTOMER],
            'receptionist' => [User::ROLE_RECEPTIONIST],
            'doctor' => [User::ROLE_DOCTOR],
        ];
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }
}
