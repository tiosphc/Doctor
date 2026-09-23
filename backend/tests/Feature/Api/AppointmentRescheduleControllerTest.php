<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentRescheduleControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_reschedules_own_pending_appointment_and_backend_calculates_end_time(): void
    {
        Notification::fake();
        [$customer, $appointment] = $this->bookableAppointment(Appointment::STATUS_PENDING, 45);
        $originalId = $appointment->id;
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), [
            'appointment_date' => '2026-09-25',
            'start_time' => '14:00',
        ])->assertOk()
            ->assertJsonPath('data.id', $originalId)
            ->assertJsonPath('data.appointment_date', '2026-09-25')
            ->assertJsonPath('data.start_time', '14:00')
            ->assertJsonPath('data.end_time', '14:45')
            ->assertJsonPath('data.status', Appointment::STATUS_PENDING)
            ->assertJsonPath('data.can_reschedule', true)
            ->assertJsonStructure(['data' => ['doctor', 'service']]);

        $this->assertDatabaseHas('appointments', [
            'id' => $originalId,
            'appointment_date' => '2026-09-25',
            'start_time' => '14:00:00',
            'end_time' => '14:45:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Notification::assertSentTo($customer, AppointmentNotification::class, function (
            AppointmentNotification $notification,
        ): bool {
            $payload = $notification->toArray($notification->appointment->user);

            return $notification->event === AppointmentNotification::EVENT_RESCHEDULED
                && $payload['previous_schedule'] === [
                    'appointment_date' => '2026-09-21',
                    'start_time' => '09:00',
                    'end_time' => '09:45',
                ]
                && $payload['new_schedule'] === [
                    'appointment_date' => '2026-09-25',
                    'start_time' => '14:00',
                    'end_time' => '14:45',
                ];
        });
    }

    public function test_customer_reschedules_confirmed_appointment_without_changing_status_and_resets_reminder(): void
    {
        Notification::fake();
        [$customer, $appointment] = $this->bookableAppointment(Appointment::STATUS_CONFIRMED);
        $appointment->update(['reminder_sent_at' => now()]);
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED);

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertNull($appointment->reminder_sent_at);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        [, $appointment] = $this->bookableAppointment();

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_customer_cannot_reschedule_another_customers_appointment_and_original_slot_remains(): void
    {
        [, $appointment] = $this->bookableAppointment();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertForbidden();

        $this->assertOriginalSchedule($appointment);
    }

    #[DataProvider('nonReschedulableStatuses')]
    public function test_returns_409_for_non_reschedulable_statuses(string $status): void
    {
        Notification::fake();
        [$customer, $appointment] = $this->bookableAppointment($status);
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Chỉ lịch hẹn đang chờ xác nhận hoặc đã xác nhận mới có thể đổi lịch.');

        $this->assertOriginalSchedule($appointment);
        Notification::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function nonReschedulableStatuses(): array
    {
        return [
            'checked in' => [Appointment::STATUS_CHECKED_IN],
            'in progress' => [Appointment::STATUS_IN_PROGRESS],
            'completed' => [Appointment::STATUS_COMPLETED],
            'cancelled' => [Appointment::STATUS_CANCELLED],
            'no show' => [Appointment::STATUS_NO_SHOW],
        ];
    }

    public function test_returns_409_when_current_appointment_is_in_the_past_or_has_started(): void
    {
        Notification::fake();
        [$customer, $past] = $this->bookableAppointment();
        $past->update(['appointment_date' => '2026-09-14']);
        $started = Appointment::factory()->for($customer)->for($past->doctor)->for($past->service)->create([
            'appointment_date' => '2026-09-15',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($past), $this->validPayload())->assertConflict();
        $this->patchJson($this->rescheduleUrl($started), $this->validPayload())->assertConflict();

        Notification::assertNothingSent();
    }

    public function test_reschedule_cutoff_allows_exactly_two_hours_and_rejects_one_second_late(): void
    {
        Notification::fake();
        [$customer, $allowed, $doctor, $service] = $this->bookableAppointment();
        $blocked = Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($customer);

        $this->travelTo('2026-09-21 07:00:00');
        $this->patchJson($this->rescheduleUrl($allowed), $this->validPayload())->assertOk();

        $this->travelTo('2026-09-21 08:00:01');
        $this->patchJson($this->rescheduleUrl($blocked), $this->validPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Lịch hẹn chỉ có thể được thay đổi trước giờ hẹn ít nhất 2 tiếng.');
        $this->assertSame('2026-09-21', $blocked->fresh()->appointment_date->toDateString());
    }

    public function test_returns_422_for_past_target_date_and_protected_fields(): void
    {
        [$customer, $appointment] = $this->bookableAppointment();
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), [
            'appointment_date' => '2026-09-14',
            'start_time' => '14:00',
            'user_id' => User::factory()->customer()->create()->id,
            'doctor_id' => Doctor::factory()->create()->id,
            'service_id' => Service::factory()->create()->id,
            'status' => Appointment::STATUS_CANCELLED,
            'end_time' => '18:00',
            'appointment_id' => 999,
            'exclude_appointment_id' => 999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'appointment_date', 'user_id', 'doctor_id', 'service_id', 'status', 'end_time',
                'appointment_id', 'exclude_appointment_id',
            ]);

        $this->assertOriginalSchedule($appointment);
    }

    public function test_returns_409_for_elapsed_target_time_outside_schedule_or_time_off(): void
    {
        [$customer, $appointment, $doctor] = $this->bookableAppointment();
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), [
            'appointment_date' => '2026-09-15',
            'start_time' => '08:00',
        ])->assertConflict();
        $this->patchJson($this->rescheduleUrl($appointment), [
            'appointment_date' => '2026-09-25',
            'start_time' => '18:00',
        ])->assertConflict();

        DoctorTimeOff::factory()->for($doctor)->create([
            'date' => '2026-09-25',
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ]);

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertConflict();
        $this->assertOriginalSchedule($appointment);
    }

    public function test_returns_422_when_doctor_no_longer_supports_service(): void
    {
        [$customer, $appointment, $doctor, $service] = $this->bookableAppointment();
        $doctor->services()->detach($service);
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);

        $this->assertOriginalSchedule($appointment);
    }

    public function test_service_duration_must_fit_inside_one_shift(): void
    {
        [$customer, $appointment, $doctor] = $this->bookableAppointment(Appointment::STATUS_PENDING, 60);
        $doctor->schedules()->where('day_of_week', 5)->delete();
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 5,
            'start_time' => '14:00:00',
            'end_time' => '14:30:00',
        ]);
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertConflict();

        $this->assertOriginalSchedule($appointment, '10:00:00');
    }

    public function test_occupied_slot_stays_blocked_but_adjacent_slot_is_available(): void
    {
        [$customer, $appointment, $doctor, $service] = $this->bookableAppointment();
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-25',
            'start_time' => '13:30:00',
            'end_time' => '14:00:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $blocking = Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-25',
            'start_time' => '15:00:00',
            'end_time' => '15:30:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($customer);

        $this->patchJson($this->rescheduleUrl($appointment), $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.start_time', '14:00');

        $this->patchJson($this->rescheduleUrl($appointment), [
            'appointment_date' => '2026-09-25',
            'start_time' => '15:00',
        ])->assertConflict()
            ->assertJsonPath('message', 'The selected appointment slot is no longer available.');
        $this->assertSame('15:00:00', $blocking->fresh()->start_time);
        $this->assertSame('14:00:00', $appointment->fresh()->start_time);
    }

    public function test_reschedule_slots_exclude_only_the_owned_current_appointment(): void
    {
        [$customer, $appointment, $doctor, $service] = $this->bookableAppointment();
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($customer);

        $response = $this->getJson($this->slotsUrl($appointment, '2026-09-21'))
            ->assertOk()
            ->assertJsonPath('data.appointment_id', $appointment->id);

        $this->assertContains('09:00', $response->json('data.slots'));
        $this->assertNotContains('10:00', $response->json('data.slots'));
        $this->getJson($this->slotsUrl($appointment, '2026-09-21').'&exclude_appointment_id=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exclude_appointment_id']);
    }

    public function test_reschedule_slot_exclusion_cannot_be_used_for_another_customers_appointment(): void
    {
        [, $appointment] = $this->bookableAppointment();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson($this->slotsUrl($appointment, '2026-09-21'))->assertForbidden();
    }

    public function test_competing_reschedules_to_same_slot_allow_exactly_one_update(): void
    {
        Notification::fake();
        [$firstCustomer, $first, $doctor, $service] = $this->bookableAppointment();
        $secondCustomer = User::factory()->customer()->create();
        $second = Appointment::factory()->for($secondCustomer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($firstCustomer);

        $this->patchJson($this->rescheduleUrl($first), $this->validPayload())->assertOk();

        Sanctum::actingAs($secondCustomer);
        $this->patchJson($this->rescheduleUrl($second), $this->validPayload())->assertConflict();

        $this->assertSame('2026-09-25', $first->fresh()->appointment_date->toDateString());
        $this->assertSame('2026-09-21', $second->fresh()->appointment_date->toDateString());
        $this->assertDatabaseCount('appointments', 2);
    }

    /** @return array{User, Appointment, Doctor, Service} */
    private function bookableAppointment(
        string $status = Appointment::STATUS_PENDING,
        int $duration = 30,
    ): array {
        $this->travelTo('2026-09-15 09:00:00');
        $customer = User::factory()->customer()->create();
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => $duration]);
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 5,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
        ]);
        $appointment = Appointment::factory()->for($customer)->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00:00',
            'end_time' => CarbonImmutable::createFromTime(9)->addMinutes($duration)->format('H:i:s'),
            'status' => $status,
        ]);

        return [$customer, $appointment, $doctor, $service];
    }

    /** @return array{appointment_date: string, start_time: string} */
    private function validPayload(): array
    {
        return [
            'appointment_date' => '2026-09-25',
            'start_time' => '14:00',
        ];
    }

    private function rescheduleUrl(Appointment $appointment): string
    {
        return "/api/my-appointments/{$appointment->id}/reschedule";
    }

    private function slotsUrl(Appointment $appointment, string $date): string
    {
        return "/api/my-appointments/{$appointment->id}/reschedule-slots?date={$date}";
    }

    private function assertOriginalSchedule(Appointment $appointment, string $expectedEnd = '09:30:00'): void
    {
        $appointment->refresh();
        $this->assertSame('2026-09-21', $appointment->appointment_date->toDateString());
        $this->assertSame('09:00:00', $appointment->start_time);
        $this->assertSame($expectedEnd, $appointment->end_time);
    }
}
