<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_creates_pending_appointment_with_server_calculated_owner_and_end_time(): void
    {
        [$doctor, $service] = $this->bookableDoctor(45);
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'note' => 'First consultation',
        ])->assertCreated()
            ->assertJsonPath('data.status', Appointment::STATUS_PENDING)
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '09:45');

        $bookingCode = $response->json('data.booking_code');
        $customerProfile = Customer::query()->whereBelongsTo($customer)->sole();
        $this->assertIsString($bookingCode);
        $this->assertMatchesRegularExpression('/^JUN-20260915-090000-000-[A-Z0-9]{2}$/', $bookingCode);

        $this->assertDatabaseHas('appointments', [
            'user_id' => $customer->id,
            'customer_id' => $customerProfile->id,
            'customer_name_snapshot' => $customerProfile->name,
            'customer_email_snapshot' => $customerProfile->primary_email,
            'customer_phone_snapshot' => $customerProfile->primary_phone,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'start_time' => '09:00:00',
            'end_time' => '09:45:00',
            'status' => Appointment::STATUS_PENDING,
            'booking_code' => $bookingCode,
        ]);
    }

    public function test_returns_422_when_customer_attempts_to_control_protected_appointment_fields(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'user_id' => User::factory()->customer()->create()->id,
            'customer_id' => 999,
            'customer_name_snapshot' => 'Tampered name',
            'customer_email_snapshot' => 'tampered@example.test',
            'customer_phone_snapshot' => '0999999999',
            'end_time' => '18:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'customer_id',
                'customer_name_snapshot',
                'customer_email_snapshot',
                'customer_phone_snapshot',
                'end_time',
                'status',
            ]);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_guest_receives_401_and_admin_receives_403_when_booking(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $payload = $this->bookingPayload($doctor, $service);

        $this->postJson('/api/appointments', $payload)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/appointments', $payload)->assertForbidden();
    }

    public function test_returns_409_when_slot_has_no_schedule_is_outside_hours_or_overlaps_time_off(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson('/api/appointments', [
            ...$this->bookingPayload($doctor, $service),
            'start_time' => '12:00',
        ])->assertConflict();

        DoctorTimeOff::factory()->for($doctor)->create([
            'date' => '2026-09-21', 'start_time' => '09:00', 'end_time' => '10:00',
        ]);

        $this->postJson('/api/appointments', $this->bookingPayload($doctor, $service))->assertConflict();

        $doctor->schedules()->delete();
        $this->postJson('/api/appointments', [
            ...$this->bookingPayload($doctor, $service),
            'start_time' => '10:00',
        ])->assertConflict();

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_returns_422_for_inactive_doctor_inactive_service_or_unassigned_service(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $customer = User::factory()->customer()->create();
        $inactiveDoctor = Doctor::factory()->inactive()->create();
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $inactiveService = Service::factory()->inactive()->create();
        $inactiveDoctor->services()->attach($service);
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', $this->bookingPayload($inactiveDoctor, $service))
            ->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);
        $this->postJson('/api/appointments', $this->bookingPayload($doctor, $inactiveService))
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
        $this->postJson('/api/appointments', $this->bookingPayload($doctor, $service))
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public function test_second_competing_booking_receives_409_and_only_one_appointment_exists(): void
    {
        [$doctor, $service] = $this->bookableDoctor(60);
        $firstCustomer = User::factory()->customer()->create();
        $secondCustomer = User::factory()->customer()->create();
        $payload = $this->bookingPayload($doctor, $service);
        Sanctum::actingAs($firstCustomer);

        $this->postJson('/api/appointments', $payload)->assertCreated();

        Sanctum::actingAs($secondCustomer);

        $this->postJson('/api/appointments', $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'The selected appointment slot is no longer available.');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_confirmed_overlap_is_rejected(): void
    {
        [$doctor, $service] = $this->bookableDoctor(30);
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson('/api/appointments', [
            ...$this->bookingPayload($doctor, $service),
            'start_time' => '09:30',
        ])->assertConflict();

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_touching_boundary_and_cancelled_overlap_allow_booking(): void
    {
        [$doctor, $service] = $this->bookableDoctor(30);
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '08:30',
            'end_time' => '09:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => Appointment::STATUS_CANCELLED,
        ]);
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson('/api/appointments', $this->bookingPayload($doctor, $service))->assertCreated();

        $this->assertDatabaseCount('appointments', 3);
    }

    public function test_customer_list_filters_and_returns_only_owned_appointments(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $customer = User::factory()->customer()->create();
        $owned = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-21',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-14',
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        Appointment::factory()->create([
            'appointment_date' => '2026-09-21',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/my-appointments?status=pending&upcoming=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id);
    }

    public function test_customer_cannot_view_another_customers_appointment(): void
    {
        $appointment = Appointment::factory()->create();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson("/api/my-appointments/{$appointment->id}")->assertForbidden();
    }

    public function test_customer_can_view_owned_appointment_detail(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create();
        Sanctum::actingAs($customer);

        $this->getJson("/api/my-appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonStructure(['data' => ['doctor', 'service']]);
    }

    public function test_customer_cannot_cancel_another_customers_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $appointment = Appointment::factory()->create([
            'appointment_date' => '2026-09-21',
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->patchJson("/api/my-appointments/{$appointment->id}/cancel")->assertForbidden();

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->fresh()->status);
    }

    public function test_customer_can_cancel_pending_and_confirmed_future_appointments(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $customer = User::factory()->customer()->create();
        $pending = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-21',
            'status' => Appointment::STATUS_PENDING,
        ]);
        $confirmed = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-22',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Sanctum::actingAs($customer);

        $this->patchJson("/api/my-appointments/{$pending->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', Appointment::STATUS_CANCELLED);
        $this->patchJson("/api/my-appointments/{$confirmed->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', Appointment::STATUS_CANCELLED);

        $this->assertDatabaseMissing('appointments', ['id' => $pending->id, 'status' => Appointment::STATUS_PENDING]);
        $this->assertDatabaseMissing('appointments', ['id' => $confirmed->id, 'status' => Appointment::STATUS_CONFIRMED]);
    }

    public function test_returns_409_when_cancelling_completed_cancelled_or_past_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $customer = User::factory()->customer()->create();
        $completed = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_COMPLETED,
        ]);
        $cancelled = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_CANCELLED,
        ]);
        $past = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-14', 'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($customer);

        $this->patchJson("/api/my-appointments/{$completed->id}/cancel")->assertConflict();
        $this->patchJson("/api/my-appointments/{$cancelled->id}/cancel")->assertConflict();
        $this->patchJson("/api/my-appointments/{$past->id}/cancel")->assertConflict();
    }

    /** @return array{Doctor, Service} */
    private function bookableDoctor(int $duration = 30): array
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => $duration]);
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);

        return [$doctor, $service];
    }

    /** @return array<string, int|string> */
    private function bookingPayload(Doctor $doctor, Service $service): array
    {
        return [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
        ];
    }
}
