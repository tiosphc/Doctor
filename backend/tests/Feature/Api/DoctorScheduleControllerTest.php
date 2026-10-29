<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorScheduleControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_and_list_non_overlapping_shifts(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ])->assertCreated();
        $this->postJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1, 'start_time' => '13:30', 'end_time' => '17:30',
        ])->assertCreated();

        $this->getJson("/api/admin/doctors/{$doctor->id}/schedules")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.day_name', 'Monday')
            ->assertJsonPath('data.1.start_time', '13:30');

        $this->assertDatabaseCount('doctor_schedules', 2);
    }

    public function test_returns_422_when_schedule_end_is_not_after_start(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '12:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['end_time']);
    }

    public function test_returns_409_when_schedule_overlaps_an_existing_shift(): void
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '14:00',
        ])->assertConflict()->assertJsonPath('message', 'The schedule overlaps an existing working period.');

        $this->assertDatabaseCount('doctor_schedules', 1);
    }

    public function test_update_ignores_itself_but_rejects_overlap_with_another_shift(): void
    {
        $doctor = Doctor::factory()->create();
        $morning = DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '13:30', 'end_time' => '17:30',
        ]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/doctors/{$doctor->id}/schedules/{$morning->id}", [
            'start_time' => '08:30',
        ])->assertOk()->assertJsonPath('data.start_time', '08:30');

        $this->patchJson("/api/admin/doctors/{$doctor->id}/schedules/{$morning->id}", [
            'end_time' => '14:00',
        ])->assertConflict();
    }

    public function test_admin_can_replace_weekly_schedule_with_create_update_and_delete(): void
    {
        $doctor = Doctor::factory()->create();
        $morning = DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        $removed = DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '17:00',
        ]);
        $this->actingAsAdmin();

        $this->putJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'schedules' => [
                ['id' => $morning->id, 'day_of_week' => 1, 'start_time' => '08:30', 'end_time' => '12:00'],
                ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('doctor_schedules', ['id' => $morning->id, 'start_time' => '08:30:00']);
        $this->assertModelMissing($removed);
        $this->assertDatabaseHas('doctor_schedules', ['doctor_id' => $doctor->id, 'day_of_week' => 2]);
    }

    public function test_weekly_schedule_rejects_duplicate_and_overlapping_shifts(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAsAdmin();

        $this->putJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'schedules' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['schedules.1.start_time']);

        $this->putJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'schedules' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
                ['day_of_week' => 1, 'start_time' => '11:00', 'end_time' => '15:00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['schedules']);

        $this->assertDatabaseCount('doctor_schedules', 0);
    }

    public function test_returns_409_when_weekly_schedule_replacement_invalidates_future_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $schedule = DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        Appointment::factory()->for($doctor)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $this->actingAsAdmin();

        $this->putJson("/api/admin/doctors/{$doctor->id}/schedules", [
            'schedules' => [],
        ])->assertConflict();

        $this->assertModelExists($schedule);
    }

    public function test_returns_409_when_deleting_schedule_would_invalidate_a_future_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $schedule = DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/doctors/{$doctor->id}/schedules/{$schedule->id}")
            ->assertConflict()
            ->assertJsonCount(1, 'details.appointment_ids');

        $this->assertModelExists($schedule);
    }

    public function test_returns_409_when_updating_schedule_would_invalidate_a_future_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $schedule = DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        Appointment::factory()->for($doctor)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/doctors/{$doctor->id}/schedules/{$schedule->id}", [
            'start_time' => '10:00',
        ])->assertConflict();

        $this->assertSame('08:00:00', $schedule->fresh()->start_time);
    }

    public function test_nested_schedule_binding_returns_404_for_another_doctor(): void
    {
        $doctor = Doctor::factory()->create();
        $schedule = DoctorSchedule::factory()->for(Doctor::factory())->create();
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/doctors/{$doctor->id}/schedules/{$schedule->id}")->assertNotFound();
    }

    public function test_customer_receives_403_and_guest_receives_401_for_schedule_management(): void
    {
        $doctor = Doctor::factory()->create();
        $payload = ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'];
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson("/api/admin/doctors/{$doctor->id}/schedules", $payload)->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->postJson("/api/admin/doctors/{$doctor->id}/schedules", $payload)->assertUnauthorized();
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }
}
