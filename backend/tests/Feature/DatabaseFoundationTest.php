<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\AestheticClinicSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_seeder_creates_complete_connected_data(): void
    {
        $this->seed(AestheticClinicSeeder::class);

        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('doctors', 4);
        $this->assertDatabaseCount('services', 5);
        $this->assertDatabaseCount('doctor_service', 12);
        $this->assertDatabaseCount('doctor_schedules', 40);
        $this->assertDatabaseCount('doctor_time_offs', 2);
        $this->assertDatabaseCount('appointments', 4);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $doctor = Doctor::query()->where('email', 'lan.nguyen@example.com')->firstOrFail();

        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertSame(3, $doctor->services()->count());
        $this->assertSame(10, $doctor->schedules()->count());
    }

    public function test_models_expose_expected_relationships_and_casts(): void
    {
        $user = User::factory()->create();
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => 45, 'price' => '650000.00']);
        $doctor->services()->attach($service);
        $schedule = DoctorSchedule::factory()->for($doctor)->create();
        $timeOff = DoctorTimeOff::factory()->for($doctor)->create();
        $appointment = Appointment::factory()
            ->for($user)
            ->for($doctor)
            ->for($service)
            ->create();

        $this->assertInstanceOf(HasMany::class, $user->appointments());
        $this->assertInstanceOf(BelongsToMany::class, $doctor->services());
        $this->assertInstanceOf(HasMany::class, $doctor->schedules());
        $this->assertInstanceOf(HasMany::class, $doctor->timeOffs());
        $this->assertInstanceOf(HasMany::class, $doctor->appointments());
        $this->assertInstanceOf(BelongsToMany::class, $service->doctors());
        $this->assertInstanceOf(HasMany::class, $service->appointments());
        $this->assertInstanceOf(BelongsTo::class, $schedule->doctor());
        $this->assertInstanceOf(BelongsTo::class, $timeOff->doctor());
        $this->assertInstanceOf(BelongsTo::class, $appointment->user());
        $this->assertInstanceOf(BelongsTo::class, $appointment->doctor());
        $this->assertInstanceOf(BelongsTo::class, $appointment->service());

        $this->assertSame(45, $service->duration);
        $this->assertSame('650000.00', $service->price);
        $this->assertSame($user->id, $appointment->user->id);
        $this->assertSame($doctor->id, $appointment->doctor->id);
        $this->assertSame($service->id, $appointment->service->id);
    }

    public function test_duplicate_doctor_service_relationship_is_rejected(): void
    {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $doctor->services()->attach($service);

        try {
            $doctor->services()->attach($service);
            $this->fail('A duplicate doctor-service relationship was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('doctor_service', 1);
        }
    }

    public function test_doctor_with_appointment_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $appointment = Appointment::factory()
            ->for($user)
            ->for($doctor)
            ->for($service)
            ->create();

        try {
            $doctor->delete();
            $this->fail('A doctor with appointment history was deleted.');
        } catch (QueryException) {
            $this->assertModelExists($doctor);
            $this->assertModelExists($appointment);
        }
    }

    public function test_deleting_unused_doctor_removes_only_operational_records(): void
    {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();
        $doctor->services()->attach($service);
        $schedule = DoctorSchedule::factory()->for($doctor)->create();
        $timeOff = DoctorTimeOff::factory()->for($doctor)->create();

        $doctor->delete();

        $this->assertModelMissing($doctor);
        $this->assertModelMissing($schedule);
        $this->assertModelMissing($timeOff);
        $this->assertModelExists($service);
        $this->assertDatabaseCount('doctor_service', 0);
    }
}
