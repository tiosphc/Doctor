<?php

namespace Tests\Feature\Api;

use App\Mail\GuestBookingOtpMail;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestAppointmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_verified_guest_can_book_and_receives_a_timestamped_public_booking_code(): void
    {
        [$doctor, $service] = $this->bookableDoctor(45);
        $token = $this->verificationToken('guest@example.com');

        $response = $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, $token))
            ->assertCreated()
            ->assertJsonPath('data.customer_type', 'guest')
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.guest.name', 'Nguyen Van A')
            ->assertJsonPath('data.status', Appointment::STATUS_PENDING)
            ->assertJsonPath('data.end_time', '09:45');

        $bookingCode = $response->json('data.booking_code');
        $this->assertMatchesRegularExpression('/^JUN-20260915-090000-000-[A-Z0-9]{2}$/', $bookingCode);
        $this->assertDatabaseHas('appointments', [
            'user_id' => null,
            'customer_id' => null,
            'customer_name_snapshot' => null,
            'customer_email_snapshot' => null,
            'customer_phone_snapshot' => null,
            'guest_name' => 'Nguyen Van A',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '0901234567',
            'booking_code' => $bookingCode,
            'start_time' => '09:00:00',
            'end_time' => '09:45:00',
            'status' => Appointment::STATUS_PENDING,
        ]);
        $response->assertJsonMissingPath('data.guest.email')->assertJsonMissingPath('data.guest.phone');
    }

    public function test_guest_requires_a_valid_unexpired_token_for_the_same_email(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $base = $this->guestPayload($doctor, $service, 'invalid-token');

        $this->postJson('/api/appointments', [...$base, 'verification_token' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['verification_token']);
        $this->postJson('/api/appointments', $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['verification_token']);

        $otherEmailToken = $this->verificationToken('other@example.com');
        $this->postJson('/api/appointments', [...$base, 'verification_token' => $otherEmailToken])
            ->assertUnprocessable()->assertJsonValidationErrors(['verification_token']);

        $expiredToken = $this->verificationToken('guest@example.com');
        $this->travel(11)->minutes();
        $this->postJson('/api/appointments', [...$base, 'verification_token' => $expiredToken])
            ->assertUnprocessable()->assertJsonValidationErrors(['verification_token']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_verification_token_is_consumed_only_after_successful_booking(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        DoctorTimeOff::factory()->for($doctor)->create([
            'date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '09:30',
        ]);
        $token = $this->verificationToken('guest@example.com');

        $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, $token))
            ->assertConflict();

        $this->postJson('/api/appointments', [
            ...$this->guestPayload($doctor, $service, $token),
            'start_time' => '10:00',
        ])->assertCreated();

        $this->postJson('/api/appointments', [
            ...$this->guestPayload($doctor, $service, $token),
            'start_time' => '10:30',
        ])->assertUnprocessable()->assertJsonValidationErrors(['verification_token']);
    }

    public function test_guest_cannot_control_server_owned_appointment_fields(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $token = $this->verificationToken('guest@example.com');

        $this->postJson('/api/appointments', [
            ...$this->guestPayload($doctor, $service, $token),
            'user_id' => 1,
            'status' => Appointment::STATUS_CONFIRMED,
            'end_time' => '18:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status', 'end_time']);
    }

    public function test_authenticated_customer_does_not_need_otp_and_cannot_submit_guest_identity(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
        ])->assertCreated();

        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '10:00',
            'guest_email' => 'guest@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors(['guest_email']);

        $this->assertDatabaseHas('appointments', [
            'user_id' => $customer->id,
            'guest_email' => null,
        ]);
        $bookingCode = Appointment::query()->where('user_id', $customer->id)->value('booking_code');
        $this->assertIsString($bookingCode);
        $this->assertMatchesRegularExpression('/^JUN-20260915-090000-000-[A-Z0-9]{2}$/', $bookingCode);
    }

    public function test_booking_code_collision_is_retried_with_a_new_secure_random_code(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $token = $this->verificationToken('guest@example.com');
        Appointment::factory()->guest()->create(['booking_code' => 'JUN-20260915-090000-000-AA']);
        Str::createRandomStringsUsingSequence([str_repeat('t', 40), 'aa', 'bb']);

        try {
            $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, $token))
                ->assertCreated()
                ->assertJsonPath('data.booking_code', 'JUN-20260915-090000-000-BB');
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_guest_booking_reuses_availability_and_double_booking_protection(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $firstToken = $this->verificationToken('first@example.com');
        $secondToken = $this->verificationToken('second@example.com');

        $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, $firstToken, 'first@example.com'))
            ->assertCreated();
        $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, $secondToken, 'second@example.com'))
            ->assertConflict()
            ->assertJsonPath('message', 'The selected appointment slot is no longer available.');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_guest_booking_rejects_time_off_inactive_entities_and_service_mismatch(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        DoctorTimeOff::factory()->for($doctor)->create([
            'date' => '2026-09-21', 'start_time' => '09:00', 'end_time' => '10:00',
        ]);
        $this->postJson('/api/appointments', $this->guestPayload(
            $doctor,
            $service,
            $this->verificationToken('timeoff@example.com'),
            'timeoff@example.com',
        ))->assertConflict();

        $inactiveDoctor = Doctor::factory()->inactive()->create();
        $inactiveDoctor->services()->attach($service);
        $this->postJson('/api/appointments', $this->guestPayload(
            $inactiveDoctor,
            $service,
            $this->verificationToken('inactive@example.com'),
            'inactive@example.com',
        ))->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);

        $unassignedDoctor = Doctor::factory()->create();
        $this->postJson('/api/appointments', $this->guestPayload(
            $unassignedDoctor,
            $service,
            $this->verificationToken('mismatch@example.com'),
            'mismatch@example.com',
        ))->assertUnprocessable()->assertJsonValidationErrors(['service_id']);

        $inactiveService = Service::factory()->inactive()->create();
        $doctor->services()->attach($inactiveService);
        $this->postJson('/api/appointments', $this->guestPayload(
            $doctor,
            $inactiveService,
            $this->verificationToken('service@example.com'),
            'service@example.com',
        ))->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public function test_guest_email_cannot_exceed_active_future_appointment_limit(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        foreach (range(1, 3) as $day) {
            Appointment::factory()->guest()->create([
                'guest_email' => 'guest@example.com',
                'appointment_date' => "2026-09-2{$day}",
                'status' => $day === 3 ? Appointment::STATUS_CONFIRMED : Appointment::STATUS_PENDING,
            ]);
        }

        $this->postJson('/api/appointments', $this->guestPayload(
            $doctor,
            $service,
            $this->verificationToken('guest@example.com'),
        ))->assertConflict()
            ->assertJsonPath('message', 'This email has reached the active appointment limit.');
    }

    public function test_cancelled_completed_and_past_appointments_do_not_count_toward_active_limit(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        Appointment::factory()->guest()->create([
            'guest_email' => 'guest@example.com',
            'appointment_date' => '2026-09-21',
            'status' => Appointment::STATUS_CANCELLED,
        ]);
        Appointment::factory()->guest()->create([
            'guest_email' => 'guest@example.com',
            'appointment_date' => '2026-09-22',
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        Appointment::factory()->guest()->create([
            'guest_email' => 'guest@example.com',
            'appointment_date' => '2026-09-14',
            'status' => Appointment::STATUS_PENDING,
        ]);

        $this->postJson('/api/appointments', $this->guestPayload(
            $doctor,
            $service,
            $this->verificationToken('guest@example.com'),
        ))->assertCreated();
    }

    public function test_lookup_requires_matching_normalized_code_and_phone_without_exposing_internal_data(): void
    {
        $appointment = Appointment::factory()->guest()->create([
            'booking_code' => 'APT-ABC12345',
            'guest_phone' => '0901234567',
        ]);

        $this->postJson('/api/guest/appointments/lookup', [
            'booking_code' => ' apt-abc12345 ',
            'phone' => '090 123 4567',
        ])->assertOk()
            ->assertJsonPath('data.booking_code', 'APT-ABC12345')
            ->assertJsonPath('data.customer_name', $appointment->guest_name)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.guest')
            ->assertJsonMissingPath('data.note');

        $this->postJson('/api/guest/appointments/lookup', [
            'booking_code' => 'APT-ABC12345', 'phone' => '0909999999',
        ])->assertNotFound();
        $this->postJson('/api/guest/appointments/lookup', [
            'booking_code' => 'APT-UNKNOWN1', 'phone' => '0901234567',
        ])->assertNotFound();

        $customer = User::factory()->customer()->create(['phone' => '0912345678']);
        $registered = Appointment::factory()->for($customer)->create();
        $this->postJson('/api/guest/appointments/lookup', [
            'booking_code' => $registered->booking_code, 'phone' => '0912345678',
        ])->assertOk()->assertJsonPath('data.customer_name', $customer->name);
    }

    public function test_admin_response_identifies_guest_and_includes_contact_details(): void
    {
        $appointment = Appointment::factory()->guest()->create([
            'guest_name' => 'Nguyen Van A',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '0901234567',
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/admin/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.customer_type', 'guest')
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.guest.name', 'Nguyen Van A')
            ->assertJsonPath('data.guest.email', 'guest@example.com')
            ->assertJsonPath('data.guest.phone', '0901234567');
    }

    public function test_guest_can_cancel_pending_and_confirmed_future_appointments(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        foreach ([Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED] as $status) {
            $appointment = Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21',
                'status' => $status,
            ]);

            $this->patchJson("/api/guest/appointments/{$appointment->booking_code}/cancel", [
                'phone' => $appointment->guest_phone,
            ])->assertOk()
                ->assertJsonPath('data.status', Appointment::STATUS_CANCELLED)
                ->assertJsonPath('data.booking_code', $appointment->booking_code);

            $this->assertDatabaseHas('audit_logs', [
                'target_id' => $appointment->id,
                'action' => 'CANCEL',
                'actor_role' => 'guest',
            ]);
        }
    }

    public function test_guest_cannot_cancel_started_finished_cancelled_no_show_or_past_appointments(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $appointments = [
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_CHECKED_IN,
            ]),
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_IN_PROGRESS,
            ]),
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_TREATMENT_DONE,
            ]),
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_COMPLETED,
            ]),
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_CANCELLED,
            ]),
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-21', 'status' => Appointment::STATUS_NO_SHOW,
            ]),
            Appointment::factory()->guest()->create([
                'appointment_date' => '2026-09-14', 'status' => Appointment::STATUS_PENDING,
            ]),
        ];

        foreach ($appointments as $index => $appointment) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.'.($index + 1)]);
            $this->patchJson("/api/guest/appointments/{$appointment->booking_code}/cancel", [
                'phone' => $appointment->guest_phone,
            ])->assertConflict();

            $this->assertDatabaseMissing('audit_logs', [
                'target_id' => $appointment->id,
                'action' => 'CANCEL',
            ]);
        }
    }

    public function test_wrong_phone_and_unknown_code_cannot_cancel_public_appointment(): void
    {
        $appointment = Appointment::factory()->guest()->create();

        $this->patchJson("/api/guest/appointments/{$appointment->booking_code}/cancel", [
            'phone' => '0909999999',
        ])->assertNotFound();
        $this->patchJson('/api/guest/appointments/APT-UNKNOWN1/cancel', [
            'phone' => $appointment->guest_phone,
        ])->assertNotFound();

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->fresh()->status);
    }

    public function test_public_customer_can_reschedule_with_matching_phone_and_booking_code_stays_immutable(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $appointment = Appointment::factory()->guest()->for($doctor)->for($service)->create([
            'guest_phone' => '0901234567',
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
        ]);
        $bookingCode = $appointment->booking_code;

        $this->postJson("/api/guest/appointments/{$bookingCode}/reschedule-slots", [
            'phone' => '0901234567',
            'date' => '2026-09-28',
        ])->assertOk()->assertJsonFragment(['10:00']);

        $this->patchJson("/api/guest/appointments/{$bookingCode}/reschedule", [
            'phone' => '0901234567',
            'appointment_date' => '2026-09-28',
            'start_time' => '10:00',
        ])->assertOk()
            ->assertJsonPath('data.booking_code', $bookingCode)
            ->assertJsonPath('data.appointment_date', '2026-09-28')
            ->assertJsonPath('data.start_time', '10:00');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'booking_code' => $bookingCode,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-28',
            'start_time' => '10:00:00',
            'reschedule_count' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $appointment->id,
            'action' => 'RESCHEDULE',
            'actor_role' => 'guest',
        ]);
    }

    public function test_public_reschedule_rejects_wrong_phone_and_protected_fields(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $appointment = Appointment::factory()->guest()->for($doctor)->for($service)->create([
            'guest_phone' => '0901234567',
            'appointment_date' => '2026-09-21',
        ]);

        $this->postJson("/api/guest/appointments/{$appointment->booking_code}/reschedule-slots", [
            'phone' => '0909999999',
            'date' => '2026-09-28',
        ])->assertNotFound();
        $this->patchJson("/api/guest/appointments/{$appointment->booking_code}/reschedule", [
            'phone' => '0901234567',
            'appointment_date' => '2026-09-28',
            'start_time' => '10:00',
            'doctor_id' => Doctor::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_customer_change_cutoff_allows_exactly_two_hours_and_rejects_one_second_late(): void
    {
        [$doctor, $service] = $this->bookableDoctor();
        $allowed = Appointment::factory()->guest()->for($doctor)->for($service)->create([
            'guest_phone' => '0901234567',
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
        ]);
        $blocked = Appointment::factory()->guest()->for($doctor)->for($service)->create([
            'guest_phone' => '0912345678',
            'appointment_date' => '2026-09-21',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);

        $this->travelTo('2026-09-21 07:00:00');
        $this->patchJson("/api/guest/appointments/{$allowed->booking_code}/cancel", [
            'phone' => '0901234567',
        ])->assertOk();

        $this->travelTo('2026-09-21 08:00:01');
        $this->patchJson("/api/guest/appointments/{$blocked->booking_code}/cancel", [
            'phone' => '0912345678',
        ])->assertConflict()
            ->assertJsonPath('message', 'Lịch hẹn chỉ có thể được hủy trước giờ hẹn ít nhất 2 tiếng.');
        $this->assertSame(Appointment::STATUS_PENDING, $blocked->fresh()->status);
    }

    public function test_guest_lookup_cancel_and_booking_have_independent_rate_limits(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/guest/appointments/lookup', [
                'booking_code' => 'APT-UNKNOWN1', 'phone' => '0901234567',
            ])->assertNotFound();
        }
        $this->postJson('/api/guest/appointments/lookup', [
            'booking_code' => 'APT-UNKNOWN1', 'phone' => '0901234567',
        ])->assertTooManyRequests();

        foreach (range(1, 5) as $attempt) {
            $this->patchJson('/api/guest/appointments/APT-UNKNOWN1/cancel', [
                'phone' => '0901234567',
            ])->assertNotFound();
        }
        $this->patchJson('/api/guest/appointments/APT-UNKNOWN1/cancel', [
            'phone' => '0901234567',
        ])->assertTooManyRequests();

        [$doctor, $service] = $this->bookableDoctor();
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, 'invalid-token'))
                ->assertUnprocessable();
        }
        $this->postJson('/api/appointments', $this->guestPayload($doctor, $service, 'invalid-token'))
            ->assertTooManyRequests();
    }

    /** @return array{Doctor, Service} */
    private function bookableDoctor(int $duration = 30): array
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => $duration]);
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        return [$doctor, $service];
    }

    private function verificationToken(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/guest-booking/request-otp', ['email' => $email])->assertOk();
        $otp = null;
        Mail::assertSent(GuestBookingOtpMail::class, function (GuestBookingOtpMail $mail) use ($email, &$otp): bool {
            $otp = $mail->otp;

            return $mail->hasTo($email);
        });
        $this->assertIsString($otp);

        return $this->postJson('/api/guest-booking/verify-otp', [
            'email' => $email,
            'otp' => $otp,
        ])->assertOk()->json('verification_token');
    }

    /** @return array<string, int|string> */
    private function guestPayload(
        Doctor $doctor,
        Service $service,
        string $token,
        string $email = 'guest@example.com',
    ): array {
        return [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
            'guest_name' => 'Nguyen Van A',
            'guest_email' => $email,
            'guest_phone' => '0901234567',
            'verification_token' => $token,
            'note' => 'Guest consultation',
        ];
    }
}
