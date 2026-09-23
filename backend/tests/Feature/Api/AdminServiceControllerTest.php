<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminServiceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_admin_service_index_paginates_five_services_per_page(): void
    {
        Service::factory()->count(6)->create();

        $this->getJson('/api/admin/services')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_admin_can_create_and_partially_update_a_service(): void
    {
        $category = ServiceCategory::factory()->create();

        $response = $this->postJson('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Skin Renewal',
            'slug' => 'skin-renewal',
            'duration' => 60,
            'price' => '1250000.50',
            'introduction' => 'Giới thiệu dịch vụ.',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Skin Renewal')
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.category', $category->name)
            ->assertJsonPath('data.slug', 'skin-renewal')
            ->assertJsonPath('data.introduction', 'Giới thiệu dịch vụ.')
            ->assertJsonPath('data.category_slug', $category->slug)
            ->assertJsonPath('data.status', Service::STATUS_ACTIVE);

        $serviceId = $response->json('data.id');

        $this->patchJson("/api/admin/services/{$serviceId}", [
            'status' => Service::STATUS_INACTIVE,
        ])->assertOk()
            ->assertJsonPath('data.status', Service::STATUS_INACTIVE);

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'name' => 'Skin Renewal',
            'status' => Service::STATUS_INACTIVE,
        ]);
    }

    public function test_basic_service_can_be_created_without_detail_content(): void
    {
        $category = ServiceCategory::factory()->create();

        $response = $this->postJson('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Basic Consultation',
            'slug' => 'basic-consultation',
            'duration' => 30,
            'price' => '300000',
        ])->assertCreated();

        $response->assertJsonPath('data.content', null);
        $this->assertDatabaseHas('services', ['slug' => 'basic-consultation', 'content' => null]);
    }

    public function test_service_payload_is_validated(): void
    {
        $this->postJson('/api/admin/services', [
            'name' => '',
            'duration' => 0,
            'price' => '-1.123',
            'status' => 'archived',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'name', 'slug', 'duration', 'price', 'status']);
    }

    public function test_service_slug_is_explicit_and_update_keeps_it_stable(): void
    {
        $category = ServiceCategory::factory()->create();
        $payload = [
            'category_id' => $category->id,
            'name' => 'Skin Renewal',
            'slug' => 'skin-renewal',
            'duration' => 60,
            'price' => '1250000.50',
        ];

        $first = $this->postJson('/api/admin/services', $payload)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'skin-renewal');
        $this->postJson('/api/admin/services', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->patchJson('/api/admin/services/'.$first->json('data.id'), [
            'name' => 'Renamed Renewal',
        ])->assertOk()->assertJsonPath('data.slug', 'skin-renewal');
    }

    public function test_admin_can_create_service_with_uploaded_image(): void
    {
        Storage::fake('public');
        $category = ServiceCategory::factory()->create();

        $response = $this->post('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Skin Renewal',
            'slug' => 'skin-renewal',
            'duration' => 60,
            'price' => '1250000.50',
            'image' => $this->validImage('service.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = Service::query()->findOrFail($response->json('data.id'))->image;

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith(config('filesystems.disks.public.url'), $response->json('data.image'));
    }

    public function test_service_upload_rejects_invalid_file_and_missing_category(): void
    {
        Storage::fake('public');

        $this->post('/api/admin/services', [
            'name' => 'Invalid Service',
            'duration' => 60,
            'price' => '1250000.50',
            'image' => UploadedFile::fake()->create('service.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'image']);
    }

    public function test_replacing_service_image_deletes_old_file_and_preserves_image_when_omitted(): void
    {
        Storage::fake('public');
        $category = ServiceCategory::factory()->create();
        $service = Service::factory()->for($category, 'category')->create();
        $oldPath = $this->validImage('old.png')->store('services', 'public');
        $service->update(['image' => $oldPath]);

        $this->post("/api/admin/services/{$service->id}", [
            '_method' => 'PATCH',
            'image' => $this->validImage('new.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $newPath = $service->refresh()->image;
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $this->patchJson("/api/admin/services/{$service->id}", ['status' => Service::STATUS_INACTIVE])->assertOk();
        $this->assertSame($newPath, $service->refresh()->image);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_admin_can_save_fixed_detail_content_and_reload_it(): void
    {
        $category = ServiceCategory::factory()->create();
        $content = $this->fixedContent();

        $response = $this->postJson('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Editorial Consultation',
            'slug' => 'editorial-consultation',
            'duration' => 60,
            'duration_note' => 'Tham khảo',
            'price' => '800000',
            'introduction' => 'Giới thiệu chi tiết.',
            'content' => $content,
        ])->assertCreated()
            ->assertJsonPath('data.introduction', 'Giới thiệu chi tiết.')
            ->assertJsonPath('data.content.benefits.items.0.title', 'Săn chắc da')
            ->assertJsonPath('data.content.process.steps.0.description', 'Tư vấn ban đầu.')
            ->assertJsonPath('data.content.results.cases.2.caption', 'Case 03')
            ->assertJsonPath('data.content.faq.items.0.answer', 'Có.');

        $serviceId = $response->json('data.id');

        $this->getJson("/api/admin/services/{$serviceId}")
            ->assertOk()
            ->assertJsonPath('data.content.results.cases.2.caption', 'Case 03');
    }

    public function test_fixed_content_rejects_generic_blocks_unknown_keys_and_html(): void
    {
        $category = ServiceCategory::factory()->create();

        $this->postJson('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Invalid Editorial Service',
            'slug' => 'invalid-editorial-service',
            'duration' => 60,
            'price' => '800000',
            'content' => [
                'blocks' => [],
                'benefits' => [
                    'title' => '<script>alert(1)</script>',
                    'description' => null,
                    'items' => [],
                ],
                'process' => ['title' => null, 'description' => null, 'steps' => []],
                'results' => ['title' => null, 'description' => null, 'cases' => [], 'disclaimer' => null],
                'faq' => ['title' => null, 'items' => []],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['content', 'content.benefits.title']);
    }

    public function test_result_images_upload_three_cases_and_omitted_sides_are_preserved(): void
    {
        Storage::fake('public');
        $category = ServiceCategory::factory()->create();
        $content = $this->fixedContent();

        $response = $this->post('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Image Editorial Service',
            'slug' => 'image-editorial-service',
            'duration' => 60,
            'price' => '800000',
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'result_images' => [
                0 => ['before_image' => $this->validImage('before-1.png'), 'after_image' => $this->validImage('after-1.png')],
                1 => ['before_image' => $this->validImage('before-2.png'), 'after_image' => $this->validImage('after-2.png')],
                2 => ['before_image' => $this->validImage('before-3.png'), 'after_image' => $this->validImage('after-3.png')],
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $service = Service::query()->findOrFail($response->json('data.id'));
        $oldBefore = $service->content['results']['cases'][0]['before_image'];
        $oldAfter = $service->content['results']['cases'][0]['after_image'];

        Storage::disk('public')->assertExists($oldBefore);
        Storage::disk('public')->assertExists($oldAfter);
        $this->assertNotNull($response->json('data.result_image_urls.0.before_image'));

        $this->post("/api/admin/services/{$service->id}", [
            '_method' => 'PATCH',
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'result_images' => [0 => ['after_image' => $this->validImage('after-replaced.png')]],
        ], ['Accept' => 'application/json'])->assertOk();

        $updated = $service->refresh();
        $this->assertSame($oldBefore, $updated->content['results']['cases'][0]['before_image']);
        $this->assertNotSame($oldAfter, $updated->content['results']['cases'][0]['after_image']);
        Storage::disk('public')->assertExists($updated->content['results']['cases'][0]['before_image']);
        Storage::disk('public')->assertMissing($oldAfter);
    }

    public function test_deleting_unused_service_removes_local_images(): void
    {
        Storage::fake('public');
        $service = Service::factory()->create();
        $imagePath = $this->validImage('service.png')->store('services', 'public');
        $resultPath = $this->validImage('result.png')->store('service-results', 'public');
        $service->update([
            'image' => $imagePath,
            'content' => array_replace_recursive($this->fixedContent(), [
                'results' => ['cases' => [['before_image' => $resultPath, 'after_image' => null, 'caption' => null]]],
            ]),
        ]);

        $this->deleteJson("/api/admin/services/{$service->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($imagePath);
        Storage::disk('public')->assertMissing($resultPath);
    }

    public function test_service_with_appointment_history_cannot_be_deleted(): void
    {
        $service = Service::factory()->create();
        Appointment::factory()->for($service)->create();

        $this->deleteJson("/api/admin/services/{$service->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Service cannot be deleted because appointment history exists. Deactivate the service instead.');

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    /** @return array<string, mixed> */
    private function fixedContent(): array
    {
        return [
            'benefits' => [
                'title' => 'Tác dụng và ưu điểm',
                'description' => 'Nội dung lợi ích.',
                'items' => [
                    ['title' => 'Săn chắc da', 'description' => 'Mô tả săn chắc.'],
                    ['title' => 'Cải thiện đường nét', 'description' => 'Mô tả đường nét.'],
                ],
            ],
            'process' => [
                'title' => 'Quy trình thực hiện',
                'description' => 'Các bước tham khảo.',
                'steps' => [
                    ['title' => 'Trao đổi', 'description' => 'Tư vấn ban đầu.'],
                    ['title' => 'Thực hiện', 'description' => 'Tiến hành theo kế hoạch.'],
                ],
            ],
            'results' => [
                'title' => 'Hiệu quả trước và sau',
                'description' => 'Mô tả kết quả.',
                'cases' => [
                    ['before_image' => null, 'after_image' => null, 'caption' => 'Case 01'],
                    ['before_image' => null, 'after_image' => null, 'caption' => 'Case 02'],
                    ['before_image' => null, 'after_image' => null, 'caption' => 'Case 03'],
                ],
                'disclaimer' => 'Kết quả có thể khác nhau tùy từng trường hợp.',
            ],
            'faq' => [
                'title' => 'Câu hỏi thường gặp',
                'items' => [['question' => 'Có cần tư vấn không?', 'answer' => 'Có.']],
            ],
        ];
    }

    private function validImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
