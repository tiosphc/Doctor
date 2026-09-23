<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlogControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_index_returns_only_published_blogs_without_content(): void
    {
        $published = Blog::factory()->create(['title' => 'Published blog']);
        Blog::factory()->unpublished()->create(['title' => 'Draft blog']);
        Blog::factory()->create(['title' => 'Future blog', 'published_at' => now()->addDay()]);

        $this->getJson('/api/blogs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.category', $published->category->name)
            ->assertJsonMissingPath('data.0.content')
            ->assertJsonPath('data.0.author.id', $published->author_id)
            ->assertJsonPath('data.0.author.name', $published->author->name)
            ->assertJsonMissingPath('data.0.author.email')
            ->assertJsonMissingPath('data.0.author.phone')
            ->assertJsonMissingPath('data.0.author.role')
            ->assertJsonPath('meta.per_page', 9);
    }

    public function test_public_index_honors_per_page_and_stable_pagination(): void
    {
        Blog::factory()->count(2)->create();

        $this->getJson('/api/blogs?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/blogs?per_page=16')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_public_detail_returns_published_blog_content_and_hides_unpublished_blog(): void
    {
        $published = Blog::factory()->create(['slug' => 'published-blog']);
        $draft = Blog::factory()->unpublished()->create(['slug' => 'draft-blog']);

        $this->getJson('/api/blogs/'.$published->slug)
            ->assertOk()
            ->assertJsonPath('data.content', $published->content)
            ->assertJsonPath('data.category', $published->category->name)
            ->assertJsonPath('data.author.name', $published->author->name);

        $this->getJson('/api/blogs/'.$draft->slug)->assertNotFound();
    }

    public function test_admin_must_be_authenticated_and_authorized(): void
    {
        $this->getJson('/api/admin/blogs')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/admin/blogs')->assertForbidden();
    }

    public function test_admin_can_create_a_published_blog_and_response_contains_content(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $payload = [
            'title' => 'Chăm sóc da sau điều trị',
            'category_id' => BlogCategory::factory()->create(['name' => 'Chăm sóc da'])->id,
            'excerpt' => 'Hướng dẫn chăm sóc da an toàn.',
            'content' => 'Nội dung bài viết chi tiết.',
            'image' => $this->fakeImage('blog-cover.png'),
        ];

        $response = $this->post('/api/admin/blogs', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.slug', 'cham-soc-da-sau-dieu-tri')
            ->assertJsonPath('data.category_id', $payload['category_id'])
            ->assertJsonPath('data.category', 'Chăm sóc da')
            ->assertJsonPath('data.content', $payload['content'])
            ->assertJsonPath('data.author.id', $admin->id)
            ->assertJsonPath('data.author.name', $admin->name)
            ->assertJsonMissingPath('data.author.email')
            ->assertJsonMissingPath('data.author.phone')
            ->assertJsonMissingPath('data.author.role');

        $blog = Blog::query()->findOrFail($response->json('data.id'));

        Storage::disk('public')->assertExists($blog->image);
        $this->assertStringEndsWith('/storage/'.$blog->image, $response->json('data.image'));

        $this->assertDatabaseHas('blogs', [
            'id' => $response->json('data.id'),
            'author_id' => $admin->id,
            'slug' => 'cham-soc-da-sau-dieu-tri',
            'title' => $payload['title'],
        ]);
    }

    public function test_admin_blog_payload_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/blogs', [
            'title' => '',
            'category_id' => 999999,
            'excerpt' => '',
            'content' => '',
            'image' => 'not-a-url',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'category_id', 'excerpt', 'content', 'image']);
    }

    public function test_admin_blog_creation_requires_category_id(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/blogs', [
            'title' => 'Blog without a category',
            'excerpt' => 'An excerpt.',
            'content' => 'Content.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_admin_slug_collision_gets_a_unique_suffix(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        Blog::factory()->create(['title' => 'Same title', 'slug' => 'same-title']);

        $this->postJson('/api/admin/blogs', [
            'title' => 'Same title',
            'category_id' => BlogCategory::factory()->create()->id,
            'excerpt' => 'Đoạn giới thiệu.',
            'content' => 'Nội dung.',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'same-title-2');
    }

    public function test_admin_index_supports_search_and_omits_content(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $category = BlogCategory::factory()->create(['name' => 'Laser chăm sóc da']);
        $matching = Blog::factory()->for($category, 'category')->create(['title' => 'Bài viết chuyên môn']);
        Blog::factory()->create(['title' => 'Không liên quan']);

        $this->getJson('/api/admin/blogs?search=Laser')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonPath('data.0.category', $category->name)
            ->assertJsonMissingPath('data.0.content')
            ->assertJsonMissingPath('data.0.author.email')
            ->assertJsonMissingPath('data.0.author.phone')
            ->assertJsonMissingPath('data.0.author.role')
            ->assertJsonPath('meta.per_page', 5);
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
