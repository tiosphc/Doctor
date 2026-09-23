<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAppointmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_lists_appointments_with_relationships_and_filters(): void
    {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $customer = User::factory()->customer()->create();
        $matching = Appointment::factory()
            ->for($doctor)
            ->for($service)
            ->for($customer)
            ->create([
                'appointment_date' => '2026-09-21',
                'status' => Appointment::STATUS_CONFIRMED,
            ]);
        Appointment::factory()->create([
            'appointment_date' => '2026-09-22',
            'status' => Appointment::STATUS_PENDING,
        ]);
        $this->actingAsAdmin();

        $url = "/api/admin/appointments?status=confirmed&doctor_id={$doctor->id}"
            ."&service_id={$service->id}&customer_id={$customer->id}&date=2026-09-21";

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.customer.id', $customer->id)
            ->assertJsonPath('data.0.doctor.id', $doctor->id)
            ->assertJsonPath('data.0.service.id', $service->id)
            ->assertJsonPath('meta.per_page', 10);

        $this->getJson('/api/admin/appointments?from=2026-09-21&to=2026-09-21')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_default_order_prioritizes_status_and_uses_status_specific_ordering(): void
    {
        $this->travelTo('2026-09-18 10:00:00');
        $pendingOld = Appointment::factory()->create([
            'status' => Appointment::STATUS_PENDING,
            'appointment_date' => '2026-09-19',
            'created_at' => '2026-09-18 08:00:00',
            'updated_at' => '2026-09-18 08:00:00',
        ]);
        $pendingNew = Appointment::factory()->create([
            'status' => Appointment::STATUS_PENDING,
            'appointment_date' => '2026-09-20',
            'created_at' => '2026-09-18 09:00:00',
            'updated_at' => '2026-09-18 09:00:00',
        ]);
        $confirmedNear = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-19',
            'start_time' => '09:00:00',
        ]);
        $confirmedFar = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-20',
            'start_time' => '09:00:00',
        ]);
        $completed = Appointment::factory()->create([
            'status' => Appointment::STATUS_COMPLETED,
            'appointment_date' => '2026-09-18',
            'start_time' => '15:00:00',
        ]);
        $cancelled = Appointment::factory()->create([
            'status' => Appointment::STATUS_CANCELLED,
            'appointment_date' => '2026-09-18',
            'start_time' => '16:00:00',
        ]);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pendingNew->id)
            ->assertJsonPath('data.1.id', $pendingOld->id)
            ->assertJsonPath('data.2.id', $confirmedNear->id)
            ->assertJsonPath('data.3.id', $confirmedFar->id)
            ->assertJsonPath('data.4.id', $completed->id)
            ->assertJsonPath('data.5.id', $cancelled->id)
            ->assertJsonStructure(['data' => [['created_at']], 'counts' => ['all', 'new', 'upcoming', 'completed', 'cancelled']]);
    }

    public function test_admin_explicit_sorts_override_business_priority_order(): void
    {
        $oldest = Appointment::factory()->create([
            'status' => Appointment::STATUS_CANCELLED,
            'appointment_date' => '2026-09-22',
            'created_at' => '2026-09-18 06:00:00',
            'updated_at' => '2026-09-18 06:00:00',
        ]);
        $newest = Appointment::factory()->create([
            'status' => Appointment::STATUS_PENDING,
            'appointment_date' => '2026-09-20',
            'created_at' => '2026-09-18 09:00:00',
            'updated_at' => '2026-09-18 09:00:00',
        ]);
        $nearest = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-19',
            'start_time' => '09:00:00',
            'created_at' => '2026-09-18 08:00:00',
            'updated_at' => '2026-09-18 08:00:00',
        ]);
        $farthest = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-25',
            'start_time' => '09:00:00',
            'created_at' => '2026-09-18 07:00:00',
            'updated_at' => '2026-09-18 07:00:00',
        ]);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/appointments?sort=nearest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $nearest->id);
        $this->getJson('/api/admin/appointments?sort=farthest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $farthest->id);
        $this->getJson('/api/admin/appointments?sort=newest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id);
        $this->getJson('/api/admin/appointments?sort=oldest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $oldest->id);
    }

    public function test_admin_quick_filters_include_upcoming_boundary_and_counts_ignore_quick_filter(): void
    {
        $this->travelTo('2026-09-18 10:00:00');
        $boundary = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-18',
            'start_time' => '10:00:00',
        ]);
        Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-18',
            'start_time' => '09:59:00',
        ]);
        $tomorrow = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-19',
        ]);
        Appointment::factory()->create(['status' => Appointment::STATUS_PENDING]);
        Appointment::factory()->create(['status' => Appointment::STATUS_COMPLETED]);
        Appointment::factory()->create(['status' => Appointment::STATUS_CANCELLED]);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/appointments?quick_filter=upcoming')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.all', 6)
            ->assertJsonPath('counts.new', 1)
            ->assertJsonPath('counts.upcoming', 2)
            ->assertJsonPath('counts.completed', 1)
            ->assertJsonPath('counts.cancelled', 1)
            ->assertJsonPath('data.0.id', $boundary->id)
            ->assertJsonPath('data.1.id', $tomorrow->id);
    }

    public function test_admin_counts_and_quick_filter_honor_combined_base_filters(): void
    {
        $this->travelTo('2026-09-18 10:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $customer = User::factory()->customer()->create();
        $attributes = [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'user_id' => $customer->id,
            'appointment_date' => '2026-09-21',
        ];
        $upcoming = Appointment::factory()->create([
            ...$attributes,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Appointment::factory()->create([...$attributes, 'status' => Appointment::STATUS_PENDING]);
        Appointment::factory()->create([...$attributes, 'status' => Appointment::STATUS_COMPLETED]);
        Appointment::factory()->create([...$attributes, 'status' => Appointment::STATUS_CANCELLED]);
        Appointment::factory()->create([
            ...$attributes,
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-23',
        ]);
        Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-21',
        ]);
        $this->actingAsAdmin();

        $this->getJson("/api/admin/appointments?quick_filter=upcoming&doctor_id={$doctor->id}"
            ."&service_id={$service->id}&customer_id={$customer->id}&from=2026-09-21&to=2026-09-22")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id)
            ->assertJsonPath('counts.all', 4)
            ->assertJsonPath('counts.new', 1)
            ->assertJsonPath('counts.upcoming', 1)
            ->assertJsonPath('counts.completed', 1)
            ->assertJsonPath('counts.cancelled', 1);

        $this->getJson("/api/admin/appointments?status=cancelled&doctor_id={$doctor->id}"
            ."&service_id={$service->id}&customer_id={$customer->id}&from=2026-09-21&to=2026-09-22")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('counts.all', 4)
            ->assertJsonPath('counts.new', 1)
            ->assertJsonPath('counts.upcoming', 1)
            ->assertJsonPath('counts.completed', 1)
            ->assertJsonPath('counts.cancelled', 1);
    }

    public function test_admin_appointment_list_rejects_unknown_quick_filter_and_sort(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/appointments?quick_filter=archived&sort=random')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quick_filter', 'sort']);
    }

    public function test_admin_can_view_appointment_detail(): void
    {
        $appointment = Appointment::factory()->create();
        $this->actingAsAdmin();

        $this->getJson("/api/admin/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonStructure(['data' => ['customer', 'doctor', 'service']]);
    }

    public function test_customer_receives_403_and_guest_receives_401_for_admin_appointments(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/admin/appointments')->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/appointments')->assertUnauthorized();
    }

    public function test_admin_can_check_in_before_scheduled_date_and_manage_front_desk_transitions(): void
    {
        $appointment = Appointment::factory()->create([
            'status' => Appointment::STATUS_PENDING,
            'appointment_date' => now()->addDay()->toDateString(),
        ]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertOk()->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED);

        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_CHECKED_IN,
        ])->assertOk()->assertJsonPath('data.status', Appointment::STATUS_CHECKED_IN);

        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_IN_PROGRESS,
        ])->assertForbidden();

        $inProgress = Appointment::factory()->create([
            'status' => Appointment::STATUS_IN_PROGRESS,
        ]);

        $this->patchJson("/api/admin/appointments/{$inProgress->id}/status", [
            'status' => Appointment::STATUS_TREATMENT_DONE,
        ])->assertForbidden();

        $treatmentDone = Appointment::factory()->create([
            'status' => Appointment::STATUS_TREATMENT_DONE,
        ]);

        $this->patchJson("/api/admin/appointments/{$treatmentDone->id}/status", [
            'status' => Appointment::STATUS_COMPLETED,
        ])->assertOk()->assertJsonPath('data.status', Appointment::STATUS_COMPLETED);

        $this->assertDatabaseHas('appointments', [
            'id' => $treatmentDone->id,
            'status' => Appointment::STATUS_COMPLETED,
        ]);
    }

    public function test_returns_409_for_invalid_status_transition(): void
    {
        $completed = Appointment::factory()->create(['status' => Appointment::STATUS_COMPLETED]);
        $cancelled = Appointment::factory()->create(['status' => Appointment::STATUS_CANCELLED]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/appointments/{$completed->id}/status", [
            'status' => Appointment::STATUS_PENDING,
        ])->assertConflict();
        $this->patchJson("/api/admin/appointments/{$cancelled->id}/status", [
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertConflict();

        $this->assertSame(Appointment::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(Appointment::STATUS_CANCELLED, $cancelled->fresh()->status);
    }

    public function test_returns_422_for_unknown_status(): void
    {
        $appointment = Appointment::factory()->create();
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => 'rescheduled',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }
}
