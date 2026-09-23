<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorTimeOffControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_full_day_and_partial_time_off(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", [
            'date' => '2026-09-21',
            'reason' => 'Conference',
        ])->assertCreated()
            ->assertJsonPath('data.full_day', true)
            ->assertJsonPath('data.start_time', null);

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", [
            'date' => '2026-09-22',
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])->assertCreated()
            ->assertJsonPath('data.full_day', false)
            ->assertJsonPath('data.end_time', '12:00');

        $this->getJson("/api/admin/doctors/{$doctor->id}/time-offs?from=2026-09-22&to=2026-09-22")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_returns_422_for_one_sided_or_reversed_partial_time_range(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", [
            'date' => '2026-09-21',
            'start_time' => '08:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['time_range']);

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", [
            'date' => '2026-09-21',
            'start_time' => '12:00',
            'end_time' => '08:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['end_time']);
    }

    public function test_admin_can_update_and_delete_time_off(): void
    {
        $doctor = Doctor::factory()->create();
        $timeOff = DoctorTimeOff::factory()->for($doctor)->create(['date' => '2026-09-21']);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/doctors/{$doctor->id}/time-offs/{$timeOff->id}", [
            'reason' => 'Updated reason',
        ])->assertOk()->assertJsonPath('data.reason', 'Updated reason');

        $this->deleteJson("/api/admin/doctors/{$doctor->id}/time-offs/{$timeOff->id}")->assertNoContent();

        $this->assertModelMissing($timeOff);
    }

    public function test_returns_409_when_time_off_conflicts_with_future_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $appointment = Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", [
            'date' => '2026-09-21',
            'start_time' => '09:30',
            'end_time' => '10:30',
        ])->assertConflict()
            ->assertJsonPath('details.appointment_ids.0', $appointment->id);

        $this->assertDatabaseCount('doctor_time_offs', 0);
    }

    public function test_nested_time_off_binding_returns_404_for_another_doctor(): void
    {
        $doctor = Doctor::factory()->create();
        $timeOff = DoctorTimeOff::factory()->for(Doctor::factory())->create();
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/doctors/{$doctor->id}/time-offs/{$timeOff->id}")->assertNotFound();
    }

    public function test_customer_receives_403_and_guest_receives_401_for_time_off_management(): void
    {
        $doctor = Doctor::factory()->create();
        $payload = ['date' => '2026-09-21'];
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", $payload)->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->postJson("/api/admin/doctors/{$doctor->id}/time-offs", $payload)->assertUnauthorized();
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }
}
