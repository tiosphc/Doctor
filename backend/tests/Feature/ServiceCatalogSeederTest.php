<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ServiceCatalogSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeder_creates_three_categories_and_five_complete_services_without_duplicates(): void
    {
        $placeholder = ServiceCategory::factory()->create([
            'name' => 'Chưa phân loại',
            'slug' => 'chua-phan-loai',
        ]);
        $legacyService = Service::factory()->for($placeholder, 'category')->create([
            'name' => 'Aesthetic Consultation',
            'slug' => 'aesthetic-consultation',
        ]);

        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);

        $this->assertDatabaseCount('service_categories', 3);
        $this->assertDatabaseCount('services', 5);
        $this->assertDatabaseMissing('service_categories', ['slug' => 'chua-phan-loai']);
        $this->assertDatabaseHas('service_categories', ['slug' => 'tu-van-da-lieu']);
        $this->assertDatabaseHas('service_categories', ['slug' => 'cham-soc-phuc-hoi-da']);
        $this->assertDatabaseHas('service_categories', ['slug' => 'tre-hoa-tham-my']);

        $preservedService = Service::query()->where('slug', 'tu-van-da-chuyen-sau')->firstOrFail();

        $this->assertSame($legacyService->id, $preservedService->id);
        $this->assertSame('Tư vấn da liễu', $preservedService->category->name);
        $this->assertSame('active', $preservedService->status);
        $this->assertArrayHasKey('benefits', $preservedService->content);
        $this->assertArrayHasKey('process', $preservedService->content);
        $this->assertArrayHasKey('results', $preservedService->content);
        $this->assertArrayHasKey('faq', $preservedService->content);
        $this->assertNotNull($preservedService->introduction);
    }
}
