<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDoctorControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_admin_can_create_and_partially_update_a_doctor(): void
    {
        $response = $this->postJson('/api/admin/doctors', [
            'name' => 'Dr. Lan Anh',
            'specialty' => 'Dermatology',
            'email' => 'lan.anh@example.com',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Dr. Lan Anh')
            ->assertJsonPath('data.status', Doctor::STATUS_ACTIVE);

        $doctorId = $response->json('data.id');
        $createdDoctor = Doctor::query()->findOrFail($doctorId);

        $this->assertGreaterThanOrEqual(Doctor::BASELINE_REVIEW_COUNT_MIN, $createdDoctor->baseline_review_count);
        $this->assertLessThanOrEqual(Doctor::BASELINE_REVIEW_COUNT_MAX, $createdDoctor->baseline_review_count);

        $this->patchJson("/api/admin/doctors/{$doctorId}", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE);

        $this->assertDatabaseHas('doctors', [
            'id' => $doctorId,
            'name' => 'Dr. Lan Anh',
            'status' => Doctor::STATUS_INACTIVE,
        ]);
    }

    public function test_admin_can_upload_and_permanently_delete_an_unused_linked_doctor(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/admin/doctors', [
            'name' => 'Dr. Upload',
            'specialty' => 'Dermatology',
            'email' => 'doctor.upload@example.test',
            'avatar' => $this->fakeImage('doctor.png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated();

        $doctor = Doctor::query()->findOrFail($response->json('data.id'));

        Storage::disk('public')->assertExists($doctor->avatar);
        $this->assertStringEndsWith('/storage/'.$doctor->avatar, $response->json('data.avatar'));

        $userId = $doctor->user_id;

        $this->deleteJson("/api/admin/doctors/{$doctor->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($doctor->avatar);
        $this->assertDatabaseMissing('doctors', ['id' => $doctor->id]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_doctor_payload_is_validated(): void
    {
        $this->postJson('/api/admin/doctors', [
            'name' => '',
            'specialty' => '',
            'email' => 'not-an-email',
            'avatar' => 'https://example.com/doctor.jpg',
            'status' => 'archived',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'specialty', 'email', 'avatar', 'status']);
    }

    public function test_admin_can_sync_a_doctors_services(): void
    {
        $doctor = Doctor::factory()->create();
        $services = Service::factory()->count(2)->create();

        $this->putJson("/api/admin/doctors/{$doctor->id}/services", [
            'service_ids' => $services->modelKeys(),
        ])->assertOk()
            ->assertJsonCount(2, 'data.services');

        $this->assertDatabaseCount('doctor_service', 2);

        $this->putJson("/api/admin/doctors/{$doctor->id}/services", [
            'service_ids' => [$services[0]->id],
        ])->assertOk()->assertJsonCount(1, 'data.services');

        $this->assertDatabaseCount('doctor_service', 1);
        $this->assertModelExists($services[1]);

        $this->putJson("/api/admin/doctors/{$doctor->id}/services", [
            'service_ids' => [$services[0]->id, $services[0]->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['service_ids.1']);
    }

    public function test_returns_409_when_removing_service_with_future_blocking_appointment(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $doctor->services()->attach($service);
        Appointment::factory()->for($doctor)->for($service)->create([
            'appointment_date' => '2026-09-21',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $this->putJson("/api/admin/doctors/{$doctor->id}/services", [
            'service_ids' => [],
        ])->assertConflict();

        $this->assertDatabaseHas('doctor_service', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_admin_can_delete_an_unused_doctor(): void
    {
        $doctor = Doctor::factory()->create();

        $this->deleteJson("/api/admin/doctors/{$doctor->id}")->assertNoContent();

        $this->assertDatabaseMissing('doctors', ['id' => $doctor->id]);
    }

    #[DataProvider('historicalAppointmentStatuses')]
    public function test_doctor_with_any_appointment_history_cannot_be_deleted(string $status): void
    {
        $doctor = Doctor::factory()->create();
        Appointment::factory()->for($doctor)->create(['status' => $status]);

        $this->deleteJson("/api/admin/doctors/{$doctor->id}")
            ->assertConflict()
            ->assertJsonPath('message', "Không thể xóa vĩnh viễn bác sĩ này vì đã có dữ liệu lịch sử. Hãy sử dụng chức năng 'Ngừng hoạt động' thay thế.");

        $this->assertDatabaseHas('doctors', ['id' => $doctor->id]);
    }

    /** @return array<string, array{string}> */
    public static function historicalAppointmentStatuses(): array
    {
        return [
            'pending' => [Appointment::STATUS_PENDING],
            'completed' => [Appointment::STATUS_COMPLETED],
            'cancelled' => [Appointment::STATUS_CANCELLED],
            'no show' => [Appointment::STATUS_NO_SHOW],
        ];
    }

    public function test_permanent_delete_removes_configuration_and_linked_account_transactionally(): void
    {
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->for($doctorUser)->create();
        $service = Service::factory()->create();
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create();
        DoctorTimeOff::factory()->for($doctor)->create();

        $this->deleteJson("/api/admin/doctors/{$doctor->id}")->assertNoContent();

        $this->assertDatabaseMissing('doctors', ['id' => $doctor->id]);
        $this->assertDatabaseMissing('users', ['id' => $doctorUser->id]);
        $this->assertDatabaseMissing('doctor_service', ['doctor_id' => $doctor->id]);
        $this->assertDatabaseMissing('doctor_schedules', ['doctor_id' => $doctor->id]);
        $this->assertDatabaseMissing('doctor_time_offs', ['doctor_id' => $doctor->id]);
        $this->assertModelExists($service);
    }

    public function test_linked_account_notification_history_prevents_permanent_delete(): void
    {
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->for($doctorUser)->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'historical-notification',
            'notifiable_type' => User::class,
            'notifiable_id' => $doctorUser->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/admin/doctors/{$doctor->id}")->assertConflict();

        $this->assertModelExists($doctor);
        $this->assertModelExists($doctorUser);
    }

    #[DataProvider('deactivationBlockingStatuses')]
    public function test_returns_409_when_deactivation_has_future_or_operational_appointment(string $status): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $doctor = Doctor::factory()->create();
        Appointment::factory()->for($doctor)->create([
            'appointment_date' => '2026-09-21',
            'status' => $status,
        ]);

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertConflict();

        $this->assertSame(Doctor::STATUS_ACTIVE, $doctor->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function deactivationBlockingStatuses(): array
    {
        return [
            'pending' => [Appointment::STATUS_PENDING],
            'confirmed' => [Appointment::STATUS_CONFIRMED],
            'checked in' => [Appointment::STATUS_CHECKED_IN],
            'in progress' => [Appointment::STATUS_IN_PROGRESS],
            'treatment done' => [Appointment::STATUS_TREATMENT_DONE],
        ];
    }

    public function test_admin_can_deactivate_doctor_without_future_blocking_appointments(): void
    {
        $doctor = Doctor::factory()->create();
        Appointment::factory()->for($doctor)->create([
            'appointment_date' => now()->subDay()->toDateString(),
            'status' => Appointment::STATUS_COMPLETED,
        ]);

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk()
            ->assertJsonPath('data.status', Doctor::STATUS_INACTIVE)
            ->assertJsonPath('data.configuration_status', 'inactive');

        $this->assertDatabaseHas('appointments', ['doctor_id' => $doctor->id]);
    }

    public function test_deactivated_linked_doctor_cannot_use_doctor_portal(): void
    {
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->for($doctorUser)->create();

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk();

        Sanctum::actingAs($doctorUser);
        $this->getJson('/api/doctor/dashboard')->assertForbidden();
    }

    public function test_reactivation_requires_services_and_schedule(): void
    {
        $doctor = Doctor::factory()->inactive()->create();

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'status' => Doctor::STATUS_ACTIVE,
        ])->assertConflict();

        $doctor->services()->attach(Service::factory()->create());
        DoctorSchedule::factory()->for($doctor)->create();

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'status' => Doctor::STATUS_ACTIVE,
        ])->assertOk()->assertJsonPath('data.is_booking_ready', true);
    }

    public function test_admin_list_filters_operational_status_and_reports_configuration(): void
    {
        $ready = Doctor::factory()->create();
        $ready->services()->attach(Service::factory()->create());
        DoctorSchedule::factory()->for($ready)->create();
        Doctor::factory()->inactive()->create();

        $this->getJson('/api/admin/doctors?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.configuration_status', 'ready')
            ->assertJsonPath('data.0.is_booking_ready', true);

        $this->getJson('/api/admin/doctors?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.configuration_status', 'inactive');
    }

    public function test_non_admin_roles_and_guests_cannot_deactivate_or_delete_doctor(): void
    {
        $doctor = Doctor::factory()->create();

        foreach ([User::ROLE_CUSTOMER, User::ROLE_RECEPTIONIST, User::ROLE_DOCTOR] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->patchJson("/api/admin/doctors/{$doctor->id}", [
                'status' => Doctor::STATUS_INACTIVE,
            ])->assertForbidden();
            $this->deleteJson("/api/admin/doctors/{$doctor->id}")->assertForbidden();
        }

        $this->app['auth']->forgetGuards();

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertUnauthorized();
        $this->deleteJson("/api/admin/doctors/{$doctor->id}")->assertUnauthorized();

        $this->assertModelExists($doctor);
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
