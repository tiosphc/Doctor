<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlogCategoryControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_category_endpoints_return_401_for_guests(): void
    {
        $this->getJson('/api/admin/blog-categories')->assertUnauthorized();
        $this->postJson('/api/admin/blog-categories', ['name' => 'Skin care'])->assertUnauthorized();
    }

    public function test_category_endpoints_return_403_for_customers(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/admin/blog-categories')->assertForbidden();
        $this->postJson('/api/admin/blog-categories', ['name' => 'Skin care'])->assertForbidden();
    }

    public function test_admin_category_list_is_alphabetical_and_includes_blog_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $beta = BlogCategory::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        $alpha = BlogCategory::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        BlogCategory::factory()->create(['name' => 'Gamma', 'slug' => 'gamma']);
        Blog::factory()->for($beta, 'category')->count(2)->create();
        Blog::factory()->for($alpha, 'category')->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/blog-categories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.blogs_count', 1)
            ->assertJsonPath('data.1.blogs_count', 2)
            ->assertJsonPath('data.2.blogs_count', 0);

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($response->json('data'), 'name'));
    }

    public function test_admin_category_list_can_paginate_five_categories_without_changing_full_list_mode(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        BlogCategory::factory()->count(6)->create();

        $this->getJson('/api/admin/blog-categories?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/admin/blog-categories')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_admin_can_create_category_with_generated_slug_and_persist_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/blog-categories', ['name' => 'Chăm sóc da'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chăm sóc da')
            ->assertJsonPath('data.slug', 'cham-soc-da')
            ->assertJsonPath('data.blogs_count', 0);

        $this->assertDatabaseHas('blog_categories', [
            'id' => $response->json('data.id'),
            'name' => 'Chăm sóc da',
            'slug' => 'cham-soc-da',
        ]);
    }

    public function test_category_creation_rejects_missing_or_too_long_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/blog-categories', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/blog-categories', ['name' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_creation_rejects_duplicate_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        BlogCategory::factory()->create(['name' => 'Skin care', 'slug' => 'skin-care']);

        $this->postJson('/api/admin/blog-categories', ['name' => 'Skin care'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }
}
