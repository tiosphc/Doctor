<?php

namespace Tests\Feature\Api;

use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Service;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DoctorControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_list_returns_five_star_baseline_summary_before_real_reviews_exist(): void
    {
        Doctor::factory()->create([
            'name' => 'Dr. Baseline',
            'baseline_review_count' => 123,
        ]);

        $this->getJson('/api/doctors')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Dr. Baseline')
            ->assertJsonPath('data.0.average_rating', 5)
            ->assertJsonPath('data.0.review_count', 123);
    }

    public function test_list_returns_only_active_doctors(): void
    {
        $active = Doctor::factory()->create(['name' => 'Dr. Active']);
        $inactive = Doctor::factory()->inactive()->create(['name' => 'Dr. Inactive']);

        $response = $this->getJson('/api/doctors');

        $response
            ->assertOk()
            ->assertJsonFragment(['id' => $active->id, 'name' => 'Dr. Active'])
            ->assertJsonMissing(['id' => $inactive->id]);
    }

    public function test_service_filter_returns_matching_doctors(): void
    {
        $service = Service::factory()->create();
        $matchingDoctor = Doctor::factory()->create(['name' => 'Dr. Matching']);
        $otherDoctor = Doctor::factory()->create(['name' => 'Dr. Other']);
        $matchingDoctor->services()->attach($service);

        $response = $this->getJson('/api/doctors?service_id='.$service->id);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingDoctor->id)
            ->assertJsonMissing(['id' => $otherDoctor->id]);
    }

    public function test_booking_filter_requires_service_and_returns_only_ready_compatible_doctors(): void
    {
        $service = Service::factory()->create();
        $ready = Doctor::factory()->create();
        $ready->services()->attach($service);
        DoctorSchedule::factory()->for($ready)->create();
        $missingSchedule = Doctor::factory()->create();
        $missingSchedule->services()->attach($service);
        $inactive = Doctor::factory()->inactive()->create();
        $inactive->services()->attach($service);
        DoctorSchedule::factory()->for($inactive)->create();

        $this->getJson('/api/doctors?booking=1')->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
        $this->getJson("/api/doctors?booking=1&service_id={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->id);
    }

    public function test_detail_contains_only_active_services_and_no_internal_contact_data(): void
    {
        $doctor = Doctor::factory()->create([
            'phone' => '0901234567',
            'email' => 'private@example.com',
        ]);
        $activeService = Service::factory()->create(['name' => 'Active Service']);
        $inactiveService = Service::factory()->inactive()->create(['name' => 'Inactive Service']);
        $doctor->services()->attach([$activeService->id, $inactiveService->id]);

        $response = $this->getJson('/api/doctors/'.$doctor->id);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.id', $activeService->id)
            ->assertJsonMissingPaths(['data.phone', 'data.email', 'data.status']);
    }

    public function test_inactive_doctor_detail_returns_404(): void
    {
        $inactive = Doctor::factory()->inactive()->create();

        $this->getJson('/api/doctors/'.$inactive->id)->assertNotFound();
    }
}
