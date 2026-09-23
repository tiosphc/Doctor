<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Mail::fake();
    }

    public function test_customer_receives_database_notification_after_booking(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        [$doctor, $service] = $this->bookableDoctor();
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $customer->id,
            'type' => AppointmentNotification::class,
        ]);
    }

    public function test_new_booking_notifies_customer_operations_and_only_the_assigned_doctor_with_role_specific_content(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        [$doctor, $service] = $this->bookableDoctor();
        $assignedDoctor = User::factory()->doctor()->create();
        $doctor->update(['user_id' => $assignedDoctor->id]);
        $otherDoctors = User::factory()->doctor()->count(2)->create();
        $admins = User::factory()->admin()->count(2)->create();
        $receptionists = User::factory()->receptionist()->count(2)->create();
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
        ])->assertCreated();

        foreach ($admins->concat($receptionists) as $recipient) {
            $notification = $recipient->notifications()->sole();

            $this->assertSame(AppointmentNotification::EVENT_CREATED, $notification->data['event']);
            $this->assertSame('Có lịch hẹn mới', $notification->data['title']);
            $this->assertSame(AppointmentNotification::AUDIENCE_STAFF, $notification->data['audience']);
        }

        $customerNotification = $customer->notifications()->sole();
        $this->assertSame('Đặt lịch thành công', $customerNotification->data['title']);
        $this->assertSame(
            $customerNotification->data['booking_code'],
            Appointment::query()->whereKey($customerNotification->data['appointment_id'])->value('booking_code'),
        );
        $this->assertStringContainsString(
            $customerNotification->data['booking_code'],
            $customerNotification->data['message'],
        );
        $this->assertSame(
            AppointmentNotification::AUDIENCE_CUSTOMER,
            $customerNotification->data['audience'],
        );

        $doctorNotification = $assignedDoctor->notifications()->sole();
        $this->assertSame(AppointmentNotification::EVENT_CREATED, $doctorNotification->data['event']);
        $this->assertSame('Có lịch hẹn mới', $doctorNotification->data['title']);
        $this->assertStringContainsString('Bạn có lịch hẹn mới với', $doctorNotification->data['message']);
        $this->assertStringNotContainsString('Lịch hẹn của bạn', $doctorNotification->data['message']);
        $this->assertSame(
            AppointmentNotification::AUDIENCE_DOCTOR,
            $doctorNotification->data['audience'],
        );
        $this->assertSame($customer->name, $doctorNotification->data['data']['customer_name']);
        $this->assertSame($service->name, $doctorNotification->data['data']['service_name']);
        $this->assertStringContainsString(
            "/doctor/appointments/{$doctorNotification->data['appointment_id']}",
            $doctorNotification->data['action_url'],
        );

        foreach ($otherDoctors as $otherDoctor) {
            $this->assertCount(0, $otherDoctor->notifications);
        }
        $this->assertDatabaseCount('notifications', 6);
    }

    public function test_customer_notifications_are_isolated_and_can_be_marked_read(): void
    {
        $customer = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create();
        $otherAppointment = Appointment::factory()->for($otherCustomer)->create();
        $this->notifyNow($customer, $appointment);
        $this->notifyNow($otherCustomer, $otherAppointment);
        $ownedNotification = $customer->notifications()->firstOrFail();
        $otherNotification = $otherCustomer->notifications()->firstOrFail();
        Sanctum::actingAs($customer);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedNotification->id);
        $this->patchJson("/api/notifications/{$otherNotification->id}/read")->assertNotFound();
        $this->patchJson("/api/notifications/{$ownedNotification->id}/read")
            ->assertOk()
            ->assertJsonPath('id', $ownedNotification->id)
            ->assertJsonPath('read_at', fn ($value): bool => is_string($value));
    }

    public function test_notification_api_returns_an_unambiguous_iso_timestamp_for_relative_time(): void
    {
        $this->travelTo('2026-09-22 11:35:00');
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create();
        $this->notifyNow($customer, $appointment);
        Sanctum::actingAs($customer);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.created_at', '2026-09-22T04:35:00.000000Z');
    }

    public function test_unread_count_and_mark_all_read(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create();
        $this->notifyNow($customer, $appointment, AppointmentNotification::EVENT_CREATED);
        $this->notifyNow($customer, $appointment, AppointmentNotification::EVENT_CONFIRMED);
        Sanctum::actingAs($customer);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
        $this->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);
    }

    public function test_admin_confirmation_and_customer_cancellation_dispatch_notifications(): void
    {
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'status' => Appointment::STATUS_PENDING,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertOk();

        $this->assertNotificationEvent($customer, AppointmentNotification::EVENT_CONFIRMED);

        Sanctum::actingAs($customer);
        $this->patchJson("/api/my-appointments/{$appointment->id}/cancel")->assertOk();

        $this->assertNotificationEvent($customer, AppointmentNotification::EVENT_CANCELLED);
    }

    public function test_guest_notification_uses_email_only_and_does_not_create_a_user(): void
    {
        Notification::fake();
        $appointment = Appointment::factory()->guest()->create();
        $userCount = User::query()->count();

        app(AppointmentNotificationService::class)->send($appointment, AppointmentNotification::EVENT_CREATED);

        Notification::assertSentOnDemand(AppointmentNotification::class, function (
            AppointmentNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ) use ($appointment): bool {
            return $notification->appointment->id === $appointment->id
                && $channels === ['mail']
                && $notifiable->routeNotificationFor('mail', $notification) === [
                    $appointment->guest_email => $appointment->guest_name,
                ]
                && str_contains($notification->toArray(new AnonymousNotifiable)['action_url'], '/appointment-lookup?booking_code=');
        });
        $this->assertSame($userCount, User::query()->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_guest_reschedule_and_cancel_notify_only_the_guest_and_relevant_operations_accounts(): void
    {
        Notification::fake();
        $this->travelTo('2026-09-15 09:00:00');
        [$doctor, $service] = $this->bookableDoctor();
        $assignedDoctor = User::factory()->doctor()->create();
        $otherDoctor = User::factory()->doctor()->create();
        $admin = User::factory()->admin()->create();
        $receptionist = User::factory()->receptionist()->create();
        $doctor->update(['user_id' => $assignedDoctor->id]);
        $appointment = Appointment::factory()->guest()->for($doctor)->for($service)->create([
            'guest_phone' => '0901234567',
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
        ]);

        $this->patchJson("/api/guest/appointments/{$appointment->booking_code}/reschedule", [
            'phone' => $appointment->guest_phone,
            'appointment_date' => '2026-09-28',
            'start_time' => '10:00',
        ])->assertOk();

        $this->patchJson("/api/guest/appointments/{$appointment->booking_code}/cancel", [
            'phone' => $appointment->guest_phone,
        ])->assertOk();

        foreach ([$admin, $receptionist] as $recipient) {
            Notification::assertSentTo($recipient, AppointmentNotification::class, fn (
                AppointmentNotification $notification,
            ): bool => in_array($notification->event, [
                AppointmentNotification::EVENT_RESCHEDULED,
                AppointmentNotification::EVENT_CANCELLED,
            ], true) && ($notification->context['audience'] ?? null) === AppointmentNotification::AUDIENCE_STAFF);
        }

        foreach ([AppointmentNotification::EVENT_RESCHEDULED, AppointmentNotification::EVENT_CANCELLED] as $event) {
            Notification::assertSentTo($assignedDoctor, AppointmentNotification::class, function (
                AppointmentNotification $notification,
            ) use ($assignedDoctor, $event): bool {
                $content = $notification->toArray($assignedDoctor);

                return $notification->event === $event
                    && ($notification->context['audience'] ?? null) === AppointmentNotification::AUDIENCE_DOCTOR
                    && ! str_contains($content['message'], 'Lịch hẹn của bạn');
            });
            Notification::assertSentOnDemand(AppointmentNotification::class, fn (
                AppointmentNotification $notification,
            ): bool => $notification->event === $event
                && ($notification->context['audience'] ?? null) === AppointmentNotification::AUDIENCE_CUSTOMER);
        }

        Notification::assertNothingSentTo($otherDoctor);
    }

    public function test_reminder_command_claims_each_appointment_only_once(): void
    {
        Notification::fake();
        $this->travelTo('2026-09-18 09:00:00');
        $customer = User::factory()->customer()->create();
        $laterAppointment = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-18',
            'start_time' => '11:00:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $earlierAppointment = Appointment::factory()->for($customer)->create([
            'appointment_date' => '2026-09-18',
            'start_time' => '10:00:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        config(['booking.notifications.reminder_lead_minutes' => 120]);
        config(['booking.notifications.reminder_batch_size' => 1]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertNotNull($laterAppointment->fresh()->reminder_sent_at);
        $this->assertNotNull($earlierAppointment->fresh()->reminder_sent_at);
        Notification::assertSentToTimes($customer, AppointmentNotification::class, 2);
    }

    public function test_notification_endpoints_require_authentication_and_support_staff_accounts(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->patchJson('/api/notifications/missing/read')->assertUnauthorized();
        $this->patchJson('/api/notifications/read-all')->assertUnauthorized();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_notification_patch_cors_preflight_succeeds_for_the_frontend_origin(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/notifications/example/read',
            server: [
                'HTTP_ORIGIN' => 'http://localhost:8080',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-xsrf-token',
            ],
        );

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8080')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->assertStringContainsString(
            'PATCH',
            (string) $response->headers->get('Access-Control-Allow-Methods'),
        );
    }

    public function test_check_in_notifies_only_assigned_doctor_and_notification_api_is_user_isolated(): void
    {
        $assignedDoctorUser = User::factory()->doctor()->create();
        $assignedDoctor = Doctor::factory()->create(['user_id' => $assignedDoctorUser->id]);
        $otherDoctorUser = User::factory()->doctor()->create();
        Doctor::factory()->create(['user_id' => $otherDoctorUser->id]);
        $receptionist = User::factory()->receptionist()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->for($assignedDoctor)->create([
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Sanctum::actingAs($receptionist);

        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CHECKED_IN);

        $notification = $assignedDoctorUser->notifications()->sole();
        $this->assertSame(AppointmentNotification::EVENT_CHECKED_IN, $notification->data['event']);
        $this->assertSame(AppointmentNotification::AUDIENCE_DOCTOR, $notification->data['audience']);
        $this->assertSame($appointment->id, $notification->data['appointment_id']);
        $this->assertCount(0, $otherDoctorUser->notifications);
        $this->assertCount(0, $receptionist->notifications);
        $this->assertCount(0, $admin->notifications);

        Sanctum::actingAs($otherDoctorUser);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($assignedDoctorUser);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.appointment_id', $appointment->id);
    }

    public function test_doctor_starting_treatment_does_not_notify_internal_accounts(): void
    {
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->create(['user_id' => $doctorUser->id]);
        $otherDoctor = User::factory()->doctor()->create();
        Doctor::factory()->create(['user_id' => $otherDoctor->id]);
        $receptionist = User::factory()->receptionist()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->for($doctor)->create([
            'status' => Appointment::STATUS_CHECKED_IN,
        ]);
        Sanctum::actingAs($doctorUser);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_IN_PROGRESS);

        $this->assertCount(0, $doctorUser->notifications);
        $this->assertCount(0, $otherDoctor->notifications);
        $this->assertCount(0, $receptionist->notifications);
        $this->assertCount(0, $admin->notifications);
    }

    public function test_doctor_treatment_completion_notifies_each_operations_account_once(): void
    {
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->create(['user_id' => $doctorUser->id]);
        $customer = User::factory()->customer()->create();
        $otherDoctors = User::factory()->doctor()->count(2)->create();
        $receptionists = User::factory()->receptionist()->count(2)->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()
            ->for($doctor)
            ->for($customer)
            ->create(['status' => Appointment::STATUS_IN_PROGRESS]);
        Sanctum::actingAs($doctorUser);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_TREATMENT_DONE);

        foreach ($receptionists->push($admin) as $recipient) {
            $notification = $recipient->notifications()->sole();

            $this->assertSame(AppointmentNotification::EVENT_TREATMENT_DONE, $notification->data['event']);
            $this->assertSame($appointment->id, $notification->data['appointment_id']);
            $this->assertSame(Appointment::STATUS_TREATMENT_DONE, $notification->data['status']);
            $this->assertSame('doctor_portal', $notification->data['source']);
            $this->assertSame(User::ROLE_DOCTOR, $notification->data['actor_role']);
            $this->assertSame($customer->name, $notification->data['data']['customer_name']);
            $this->assertSame($doctor->name, $notification->data['data']['doctor_name']);
            $this->assertStringContainsString(
                $recipient->isAdmin() ? '/admin/appointments/' : '/receptionist/appointments/',
                $notification->data['action_url'],
            );
        }

        $this->assertCount(0, $doctorUser->notifications);
        foreach ($otherDoctors as $otherDoctor) {
            $this->assertCount(0, $otherDoctor->notifications);
        }
        $this->assertCount(0, $customer->notifications);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")
            ->assertConflict();

        foreach ($receptionists as $recipient) {
            $this->assertCount(1, $recipient->fresh()->notifications);
        }
    }

    public function test_failed_doctor_transition_does_not_notify_operations(): void
    {
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->create(['user_id' => $doctorUser->id]);
        $receptionist = User::factory()->receptionist()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->for($doctor)->create([
            'status' => Appointment::STATUS_CHECKED_IN,
        ]);
        Sanctum::actingAs($doctorUser);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")
            ->assertConflict();

        $this->assertCount(0, $receptionist->notifications);
        $this->assertCount(0, $admin->notifications);
    }

    public function test_final_completion_notifies_customer_without_creating_internal_notification(): void
    {
        $customer = User::factory()->customer()->create();
        $receptionist = User::factory()->receptionist()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'status' => Appointment::STATUS_TREATMENT_DONE,
        ]);
        Sanctum::actingAs($receptionist);

        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_COMPLETED);

        $this->assertNotificationEvent($customer, AppointmentNotification::EVENT_COMPLETED);
        $this->assertCount(0, $receptionist->notifications);
        $this->assertCount(0, $admin->notifications);
    }

    public function test_staff_notification_listing_is_isolated_to_the_authenticated_account(): void
    {
        $firstReceptionist = User::factory()->receptionist()->create();
        $secondReceptionist = User::factory()->receptionist()->create();
        $appointment = Appointment::factory()->create();
        $this->notifyNow($firstReceptionist, $appointment, AppointmentNotification::EVENT_TREATMENT_DONE);
        $this->notifyNow($secondReceptionist, $appointment, AppointmentNotification::EVENT_TREATMENT_DONE);
        $ownedNotification = $firstReceptionist->notifications()->sole();
        $otherNotification = $secondReceptionist->notifications()->sole();
        Sanctum::actingAs($firstReceptionist);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedNotification->id);
        $this->patchJson("/api/notifications/{$otherNotification->id}/read")->assertNotFound();
    }

    private function notifyNow(
        User $recipient,
        Appointment $appointment,
        string $event = AppointmentNotification::EVENT_CREATED,
    ): void {
        $recipient->notifyNow(new AppointmentNotification($appointment->load(['doctor', 'service']), $event));
    }

    private function assertNotificationEvent(User $customer, string $event): void
    {
        $this->assertTrue($customer->notifications()->get()->contains(
            fn ($notification): bool => ($notification->data['event'] ?? null) === $event,
        ));
    }

    /** @return array{Doctor, Service} */
    private function bookableDoctor(): array
    {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        return [$doctor, $service];
    }
}
