<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ReviewInvitationNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffPortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_receptionist_customer_search_reads_canonical_customers_including_userless_records(): void
    {
        $linkedUser = User::factory()->customer()->create();
        $linked = Customer::factory()->withUser($linkedUser)->create([
            'name' => 'Canonical Mai',
            'primary_email' => 'mai@example.test',
            'normalized_email' => 'mai@example.test',
        ]);
        Customer::factory()->guest()->create(['name' => 'Historic Guest']);
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->getJson('/api/receptionist/customers?search=mai')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $linked->id)
            ->assertJsonPath('data.0.user_id', $linkedUser->id)
            ->assertJsonPath('data.0.email', 'mai@example.test');
    }

    public function test_admin_must_create_doctor_accounts_from_doctor_management(): void
    {
        $doctor = Doctor::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/staff', [
            'name' => 'Dr Staff',
            'email' => 'doctor.staff@example.test',
            'phone' => '0900000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_DOCTOR,
            'doctor_id' => $doctor->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['role', 'doctor_id']);
        $this->assertDatabaseMissing('users', ['email' => 'doctor.staff@example.test']);
        $this->assertDatabaseHas('doctors', ['id' => $doctor->id, 'user_id' => null]);
    }

    public function test_admin_cannot_link_a_doctor_already_linked_to_another_staff_user(): void
    {
        $doctor = Doctor::factory()->create();
        $linked = User::factory()->doctor()->create();
        $doctor->update(['user_id' => $linked->id]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/staff', [
            'name' => 'Second Staff',
            'email' => 'second.staff@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_DOCTOR,
            'doctor_id' => $doctor->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_admin_can_create_receptionist_without_doctor_link(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/staff', [
            'name' => 'Front Desk',
            'email' => 'front.desk@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_RECEPTIONIST,
        ]);

        $response->assertCreated()->assertJsonPath('data.role', User::ROLE_RECEPTIONIST);
        $this->assertDatabaseHas('users', [
            'email' => 'front.desk@example.test',
            'role' => User::ROLE_RECEPTIONIST,
        ]);
    }

    public function test_receptionist_can_confirm_but_cannot_start_appointment(): void
    {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $appointment = Appointment::factory()->for($doctor)->for($service)->create([
            'status' => Appointment::STATUS_PENDING,
            'appointment_date' => now()->addDay()->toDateString(),
        ]);
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED);
        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/start")
            ->assertNotFound();
    }

    public function test_doctor_can_only_update_linked_appointments(): void
    {
        Notification::fake([ReviewInvitationNotification::class]);
        $doctor = Doctor::factory()->create();
        $user = User::factory()->doctor()->create();
        $doctor->update(['user_id' => $user->id]);
        $appointment = Appointment::factory()->for($doctor)->create(['status' => Appointment::STATUS_CHECKED_IN]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_IN_PROGRESS);
        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_TREATMENT_DONE);
        Notification::assertNotSentTo($appointment->user, ReviewInvitationNotification::class);
    }

    public function test_receptionist_can_check_in_before_or_after_scheduled_date_and_no_show_after_grace_period(): void
    {
        $this->travelTo('2026-09-18 10:00:00');
        $doctor = Doctor::factory()->create();
        $future = Appointment::factory()->for($doctor)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-19',
        ]);
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->patchJson("/api/receptionist/appointments/{$future->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CHECKED_IN);

        $past = Appointment::factory()->for($doctor)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-17',
        ]);
        $this->patchJson("/api/receptionist/appointments/{$past->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CHECKED_IN);

        $this->assertSame(Appointment::STATUS_CHECKED_IN, $future->fresh()->status);
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $past->fresh()->status);

        $today = Appointment::factory()->for($doctor)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-09-18',
            'start_time' => '10:30:00',
        ]);
        $this->patchJson("/api/receptionist/appointments/{$today->id}/no-show")->assertConflict();

        $this->travelTo('2026-09-18 10:46:00');
        $this->patchJson("/api/receptionist/appointments/{$today->id}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_NO_SHOW);
    }

    public function test_doctor_cannot_view_or_update_another_doctors_appointment(): void
    {
        $owner = User::factory()->doctor()->create();
        $otherUser = User::factory()->doctor()->create();
        $ownerDoctor = Doctor::factory()->create(['user_id' => $owner->id]);
        $otherDoctor = Doctor::factory()->create(['user_id' => $otherUser->id]);
        $appointment = Appointment::factory()->for($otherDoctor)->create([
            'status' => Appointment::STATUS_CHECKED_IN,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/doctor/appointments/{$appointment->id}")->assertNotFound();
        $this->patchJson("/api/doctor/appointments/{$appointment->id}/start")->assertNotFound();
        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")->assertNotFound();
        $this->assertDatabaseHas('doctors', ['id' => $ownerDoctor->id, 'user_id' => $owner->id]);
    }

    public function test_doctor_cannot_skip_examination_or_complete_the_final_appointment_step(): void
    {
        $user = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->create(['user_id' => $user->id]);
        $appointment = Appointment::factory()->for($doctor)->create([
            'status' => Appointment::STATUS_CHECKED_IN,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")
            ->assertConflict();
        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_COMPLETED,
        ])->assertForbidden();

        $this->assertSame(Appointment::STATUS_CHECKED_IN, $appointment->fresh()->status);
    }

    public function test_doctor_without_linked_profile_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson('/api/doctor/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'A linked doctor profile is required.');
    }

    public function test_receptionist_cannot_use_admin_staff_api_and_invalid_transition_is_rejected(): void
    {
        $appointment = Appointment::factory()->create(['status' => Appointment::STATUS_PENDING]);
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->getJson('/api/admin/staff')->assertForbidden();
        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/no-show")->assertConflict();
    }

    public function test_customer_cannot_access_staff_portals(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/receptionist/dashboard')->assertForbidden();
        $this->getJson('/api/doctor/dashboard')->assertForbidden();
        $this->getJson('/api/admin/staff')->assertForbidden();
    }
}
