<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AvailableSlotControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_30_minute_service_slots_without_exceeding_schedule_end(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule(30, '08:00', '12:00');

        $this->getJson($this->slotsUrl($doctor, $service))
            ->assertOk()
            ->assertJsonPath('data.slots', [
                '08:00', '08:30', '09:00', '09:30',
                '10:00', '10:30', '11:00', '11:30',
            ]);
    }

    public function test_service_duration_may_differ_from_slot_grid(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule(45, '08:00', '10:00');

        $this->getJson($this->slotsUrl($doctor, $service))
            ->assertOk()
            ->assertJsonPath('data.slots', ['08:00', '08:30', '09:00']);
    }

    public function test_multiple_shifts_do_not_allow_appointments_across_lunch_break(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule(60, '08:00', '12:00');
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '13:30', 'end_time' => '17:30',
        ]);

        $response = $this->getJson($this->slotsUrl($doctor, $service))->assertOk();

        $this->assertNotContains('11:30', $response->json('data.slots'));
        $this->assertContains('13:30', $response->json('data.slots'));
        $this->assertContains('16:30', $response->json('data.slots'));
    }

    public function test_full_day_time_off_removes_all_slots(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule();
        DoctorTimeOff::factory()->for($doctor)->create([
            'date' => '2026-09-21', 'start_time' => null, 'end_time' => null,
        ]);

        $this->getJson($this->slotsUrl($doctor, $service))
            ->assertOk()
            ->assertJsonPath('data.slots', []);
    }

    public function test_partial_time_off_removes_only_overlapping_slots(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule();
        DoctorTimeOff::factory()->for($doctor)->create([
            'date' => '2026-09-21', 'start_time' => '09:30', 'end_time' => '10:30',
        ]);

        $this->getJson($this->slotsUrl($doctor, $service))
            ->assertOk()
            ->assertJsonPath('data.slots', ['08:00', '08:30', '09:00', '10:30', '11:00', '11:30']);
    }

    public function test_pending_and_confirmed_block_but_cancelled_and_completed_do_not(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule();
        foreach ([
            ['08:00', '08:30', Appointment::STATUS_PENDING],
            ['09:00', '09:30', Appointment::STATUS_CONFIRMED],
            ['10:00', '10:30', Appointment::STATUS_CANCELLED],
            ['11:00', '11:30', Appointment::STATUS_COMPLETED],
        ] as [$startTime, $endTime, $status]) {
            Appointment::factory()->for($doctor)->for($service)->create([
                'appointment_date' => '2026-09-21',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => $status,
            ]);
        }

        $response = $this->getJson($this->slotsUrl($doctor, $service))->assertOk();
        $slots = $response->json('data.slots');

        $this->assertNotContains('08:00', $slots);
        $this->assertNotContains('09:00', $slots);
        $this->assertContains('10:00', $slots);
        $this->assertContains('11:00', $slots);
    }

    public function test_touching_an_appointment_boundary_is_available(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule();
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $response = $this->getJson($this->slotsUrl($doctor, $service))->assertOk();

        $this->assertContains('09:30', $response->json('data.slots'));
    }

    public function test_rejects_inactive_doctor_inactive_service_and_unassigned_service(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $inactiveDoctor = Doctor::factory()->inactive()->create();
        $activeDoctor = Doctor::factory()->create();
        $activeService = Service::factory()->create();
        $inactiveService = Service::factory()->inactive()->create();
        $inactiveDoctor->services()->attach($activeService);

        $this->getJson($this->slotsUrl($inactiveDoctor, $activeService))
            ->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);
        $this->getJson($this->slotsUrl($activeDoctor, $inactiveService))
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
        $this->getJson($this->slotsUrl($activeDoctor, $activeService))
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public function test_returns_422_for_past_date(): void
    {
        [$doctor, $service] = $this->bookableDoctorWithSchedule();

        $this->getJson("/api/doctors/{$doctor->id}/available-slots?date=2026-09-14&service_id={$service->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_today_does_not_return_elapsed_slots(): void
    {
        $this->travelTo('2026-09-15 09:15:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => 30]);
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);

        $response = $this->getJson(
            "/api/doctors/{$doctor->id}/available-slots?date=2026-09-15&service_id={$service->id}",
        )->assertOk();

        $this->assertNotContains('09:00', $response->json('data.slots'));
        $this->assertContains('09:30', $response->json('data.slots'));
    }

    /** @return array{Doctor, Service} */
    private function bookableDoctorWithSchedule(
        int $duration = 30,
        string $startTime = '08:00',
        string $endTime = '12:00',
    ): array {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => $duration]);
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return [$doctor, $service];
    }

    private function slotsUrl(Doctor $doctor, Service $service): string
    {
        return "/api/doctors/{$doctor->id}/available-slots?date=2026-09-21&service_id={$service->id}";
    }
}
