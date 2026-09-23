<?php

namespace Tests\Feature\Api;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceCategoryControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_category_endpoints_return_401_for_guests(): void
    {
        $this->getJson('/api/admin/service-categories')->assertUnauthorized();
        $this->postJson('/api/admin/service-categories', ['name' => 'Skin Care'])->assertUnauthorized();
    }

    public function test_category_endpoints_return_403_for_customers(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/admin/service-categories')->assertForbidden();
        $this->postJson('/api/admin/service-categories', ['name' => 'Skin Care'])->assertForbidden();
    }

    public function test_admin_category_list_is_alphabetical_and_includes_service_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $beta = ServiceCategory::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        $alpha = ServiceCategory::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        ServiceCategory::factory()->create(['name' => 'Gamma', 'slug' => 'gamma']);
        Service::factory()->for($beta, 'category')->count(2)->create();
        Service::factory()->for($alpha, 'category')->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/service-categories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.services_count', 1)
            ->assertJsonPath('data.1.services_count', 2)
            ->assertJsonPath('data.2.services_count', 0);

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($response->json('data'), 'name'));
    }

    public function test_admin_category_list_can_paginate_five_categories_without_changing_full_list_mode(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        ServiceCategory::factory()->count(6)->create();

        $this->getJson('/api/admin/service-categories?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/admin/service-categories')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_admin_can_create_a_category_with_generated_slug_and_persist_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/service-categories', ['name' => 'Skin Care'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Skin Care')
            ->assertJsonPath('data.slug', 'skin-care')
            ->assertJsonPath('data.services_count', 0);

        $this->assertDatabaseHas('service_categories', [
            'id' => $response->json('data.id'),
            'name' => 'Skin Care',
            'slug' => 'skin-care',
        ]);
    }

    public function test_category_creation_rejects_missing_or_too_long_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/service-categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/service-categories', ['name' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_creation_rejects_duplicate_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        ServiceCategory::factory()->create(['name' => 'Skin Care', 'slug' => 'skin-care']);

        $this->postJson('/api/admin/service-categories', ['name' => 'Skin Care'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_public_category_list_is_alphabetical_and_contains_active_services_only(): void
    {
        $alpha = ServiceCategory::factory()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'short_description' => 'Alpha description',
            'hero_image' => 'https://example.com/alpha.jpg',
        ]);
        $beta = ServiceCategory::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        ServiceCategory::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
        Service::factory()->for($alpha, 'category')->create(['name' => 'Zeta Service', 'slug' => 'zeta-service']);
        Service::factory()->for($alpha, 'category')->create(['name' => 'Alpha Service', 'slug' => 'alpha-service']);
        Service::factory()->for($alpha, 'category')->inactive()->create([
            'name' => 'Inactive Service',
            'slug' => 'inactive-service',
        ]);
        Service::factory()->for($beta, 'category')->create(['name' => 'Beta Service', 'slug' => 'beta-service']);

        $response = $this->getJson('/api/service-categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'alpha')
            ->assertJsonPath('data.0.short_description', 'Alpha description')
            ->assertJsonPath('data.0.hero_image', 'https://example.com/alpha.jpg')
            ->assertJsonPath('data.0.services_count', 2)
            ->assertJsonCount(2, 'data.0.services')
            ->assertJsonPath('data.0.services.0.name', 'Alpha Service')
            ->assertJsonPath('data.0.services.1.name', 'Zeta Service')
            ->assertJsonPath('data.0.services.0.category', 'Alpha')
            ->assertJsonPath('data.0.services.0.category_slug', 'alpha');

        $this->assertSame(['Alpha', 'Beta'], array_column($response->json('data'), 'name'));
    }

    public function test_public_category_detail_returns_only_categories_with_active_services(): void
    {
        $category = ServiceCategory::factory()->create(['slug' => 'skin-care']);
        $service = Service::factory()->for($category, 'category')->create(['slug' => 'facial-treatment']);
        Service::factory()->for($category, 'category')->inactive()->create();

        $this->getJson('/api/service-categories/skin-care')
            ->assertOk()
            ->assertJsonPath('data.slug', 'skin-care')
            ->assertJsonPath('data.services_count', 1)
            ->assertJsonPath('data.services.0.slug', $service->slug);

        $empty = ServiceCategory::factory()->create(['slug' => 'empty-category']);

        $this->getJson('/api/service-categories/'.$empty->slug)->assertNotFound();
    }

    public function test_nested_public_service_is_scoped_to_category_and_active_status(): void
    {
        $alpha = ServiceCategory::factory()->create(['slug' => 'alpha']);
        $beta = ServiceCategory::factory()->create(['slug' => 'beta']);
        Service::factory()->for($alpha, 'category')->create(['slug' => 'alpha-service']);
        Service::factory()->for($beta, 'category')->create(['slug' => 'beta-service']);
        Service::factory()->for($alpha, 'category')->inactive()->create(['slug' => 'inactive-service']);

        $this->getJson('/api/service-categories/alpha/services/alpha-service')
            ->assertOk()
            ->assertJsonPath('data.slug', 'alpha-service')
            ->assertJsonPath('data.category_slug', 'alpha');

        $this->getJson('/api/service-categories/alpha/services/beta-service')->assertNotFound();
        $this->getJson('/api/service-categories/alpha/services/inactive-service')->assertNotFound();
    }

    public function test_admin_can_create_category_with_description_and_hero_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->post('/api/admin/service-categories', [
            'name' => 'Skin Care',
            'short_description' => 'Treatments for healthier skin.',
            'hero_image' => $this->validImage('hero.png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.short_description', 'Treatments for healthier skin.');

        $category = ServiceCategory::query()->findOrFail($response->json('data.id'));

        $this->assertStringStartsWith('service-categories/', $category->hero_image);
        Storage::disk('public')->assertExists($category->hero_image);
        $this->assertStringStartsWith(config('filesystems.disks.public.url'), $response->json('data.hero_image'));
    }

    public function test_category_hero_image_rejects_invalid_file_and_description_length(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->post('/api/admin/service-categories', [
            'name' => 'Invalid Category',
            'short_description' => str_repeat('a', 1001),
            'hero_image' => UploadedFile::fake()->create('hero.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['short_description', 'hero_image']);
    }

    private function validImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
