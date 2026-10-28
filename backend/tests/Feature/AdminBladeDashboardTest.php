<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminBladeDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_customer_cannot_open_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->customer()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_sees_real_kpis_erp_data_and_react_bridge_links(): void
    {
        $this->withoutVite();
        $this->travelTo('2026-09-21 10:00:00');
        config()->set('app.frontend_url', 'https://frontend.example.test');

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $doctor = Doctor::factory()->create(['name' => 'BS. An']);
        $service = Service::factory()->create(['name' => 'Laser']);
        $appointment = Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()
            ->assertViewIs('admin.dashboard.index')
            ->assertViewHas('dashboard', fn (array $dashboard): bool => $dashboard['overview']['doctors'] === 1
                && $dashboard['overview']['services'] === 1
                && $dashboard['overview']['appointments'] === 1
                && $dashboard['overview']['customers'] === 1
                && $dashboard['today']['appointments'] === 1
                && $dashboard['today_appointments'][0]['id'] === $appointment->id)
            ->assertViewHas('erp', fn (array $erp): bool => $erp['period']['from'] === '2026-08-23'
                && $erp['period']['to'] === '2026-09-21'
                && $erp['sales']['net'] === '0.00')
            ->assertSee('BS. An')
            ->assertSee('Laser')
            ->assertSee('https://frontend.example.test/admin/appointments/'.$appointment->id, false)
            ->assertSee(route('admin.web.reports.index'), false);
    }

    public function test_admin_can_filter_appointment_chart_without_changing_erp_period(): void
    {
        $this->withoutVite();
        $this->travelTo('2026-09-21 10:00:00');

        $response = $this->actingAs(User::factory()->admin()->create())->get('/admin?period=month');

        $response->assertOk()
            ->assertViewHas('dashboard', fn (array $dashboard): bool => $dashboard['appointment_statistics']['period'] === 'month'
                && $dashboard['appointment_statistics']['from'] === '2026-09-01'
                && count($dashboard['appointment_statistics']['items']) === 30)
            ->assertViewHas('erp', fn (array $erp): bool => $erp['period']['from'] === '2026-08-23');
    }

    public function test_invalid_period_is_rejected_by_existing_dashboard_request(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from('/admin')
            ->get('/admin?period=quarter')
            ->assertRedirect('/admin')
            ->assertSessionHasErrors(['period']);
    }
}
