<?php

namespace Tests\Feature\Api;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ServiceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_list_returns_only_active_services(): void
    {
        $active = Service::factory()->create(['name' => 'Active Consultation']);
        $inactive = Service::factory()->inactive()->create(['name' => 'Inactive Consultation']);

        $response = $this->getJson('/api/services');

        $response
            ->assertOk()
            ->assertJsonFragment(['id' => $active->id, 'name' => 'Active Consultation'])
            ->assertJsonMissing(['id' => $inactive->id]);
    }

    public function test_inactive_service_detail_returns_404(): void
    {
        $inactive = Service::factory()->inactive()->create();

        $this->getJson('/api/services/'.$inactive->id)->assertNotFound();
    }

    public function test_service_detail_supports_numeric_id_and_slug_identifiers(): void
    {
        $category = ServiceCategory::factory()->create(['name' => 'Skin Care', 'slug' => 'skin-care']);
        $service = Service::factory()->for($category, 'category')->create([
            'name' => 'Facial Treatment',
            'slug' => 'facial-treatment',
        ]);

        $this->getJson('/api/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('data.slug', 'facial-treatment')
            ->assertJsonPath('data.category', 'Skin Care')
            ->assertJsonPath('data.category_slug', 'skin-care');

        $this->getJson('/api/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('data.id', $service->id);
    }

    public function test_search_filters_services_by_name(): void
    {
        Service::factory()->create(['name' => 'Skin Consultation']);
        Service::factory()->create(['name' => 'Follow-up Visit']);

        $response = $this->getJson('/api/services?search=skin');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Skin Consultation');
    }

    public function test_search_filters_services_by_category_name_and_returns_category(): void
    {
        $category = ServiceCategory::factory()->create(['name' => 'Skin Care', 'slug' => 'skin-care']);
        Service::factory()->for($category, 'category')->create(['name' => 'Facial Treatment']);
        Service::factory()->create(['name' => 'General Consultation']);

        $response = $this->getJson('/api/services?search=skin')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Facial Treatment')
            ->assertJsonPath('data.0.category', 'Skin Care');

        $this->assertArrayHasKey('category', $response->json('data.0'));
    }

    public function test_list_is_paginated(): void
    {
        Service::factory()->count(16)->create();

        $response = $this->getJson('/api/services');

        $response
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 16)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_public_detail_returns_editorial_fields_and_resolves_content_images(): void
    {
        $category = ServiceCategory::factory()->create();
        $service = Service::factory()->for($category, 'category')->create([
            'slug' => 'editorial-public-service',
            'description' => 'Legacy description.',
            'short_description' => null,
            'introduction' => 'Introduction text.',
            'duration_note' => 'Tham khảo',
            'price_note' => 'Giá tham khảo',
            'hero_image' => 'service-heroes/hero.jpg',
            'hero_disclaimer' => 'Thông tin tham khảo.',
            'cta_label' => 'Đặt lịch tư vấn',
            'sort_order' => 4,
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
            'content' => [
                'blocks' => [
                    [
                        'id' => 'image-1',
                        'type' => 'image_text',
                        'image' => 'service-content/image.jpg',
                    ],
                ],
            ],
        ]);

        $this->getJson('/api/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('data.short_description', 'Legacy description.')
            ->assertJsonPath('data.introduction', 'Introduction text.')
            ->assertJsonPath('data.duration_note', 'Tham khảo')
            ->assertJsonPath('data.price_note', 'Giá tham khảo')
            ->assertJsonPath('data.hero_image', url('/storage/service-heroes/hero.jpg'))
            ->assertJsonPath('data.content.blocks.0.image', url('/storage/service-content/image.jpg'))
            ->assertJsonPath('data.status', Service::STATUS_ACTIVE)
            ->assertJsonPath('data.content.blocks.0.id', 'image-1');
    }

    public function test_public_detail_normalizes_legacy_overview_process_and_faq_blocks(): void
    {
        $service = Service::factory()->create([
            'slug' => 'legacy-block-service',
            'content' => [
                'blocks' => [
                    ['type' => 'overview', 'heading' => 'Benefits', 'body' => 'Overview', 'expect_items' => ['One']],
                    ['type' => 'process', 'heading' => 'Process', 'steps' => [['title' => 'Step', 'text' => 'Description']]],
                    ['type' => 'faq', 'heading' => 'FAQ', 'items' => [['q' => 'Question?', 'a' => 'Answer.']]],
                ],
            ],
        ]);

        $this->getJson('/api/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('data.content.benefits.title', 'Benefits')
            ->assertJsonPath('data.content.benefits.items.0.title', 'One')
            ->assertJsonPath('data.content.process.steps.0.description', 'Description')
            ->assertJsonPath('data.content.faq.items.0.answer', 'Answer.');
    }

    public function test_public_detail_allows_null_editorial_content(): void
    {
        $service = Service::factory()->create(['slug' => 'legacy-service', 'content' => null]);

        $this->getJson('/api/services/'.$service->slug)
            ->assertOk()
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.short_description', $service->description);
    }

    public function test_public_list_orders_services_by_sort_order_then_name_and_id(): void
    {
        Service::factory()->create(['name' => 'Later', 'sort_order' => 2]);
        Service::factory()->create(['name' => 'First', 'sort_order' => 1]);
        Service::factory()->create(['name' => 'Second', 'sort_order' => 1]);

        $response = $this->getJson('/api/services')->assertOk();

        $this->assertSame(['First', 'Second', 'Later'], array_column($response->json('data'), 'name'));
    }
}
