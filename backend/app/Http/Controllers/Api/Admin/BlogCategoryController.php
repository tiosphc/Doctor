<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlogCategoryRequest;
use App\Http\Resources\AdminBlogCategoryResource;
use App\Models\BlogCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BlogCategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $categories = BlogCategory::query()
            ->withCount('blogs')
            ->orderBy('name')
            ->orderBy('id');

        $results = isset($validated['per_page'])
            ? $categories->paginate($validated['per_page'])->withQueryString()
            : $categories->get();

        return AdminBlogCategoryResource::collection($results);
    }

    public function store(StoreBlogCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $category = BlogCategory::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
        ]);

        return (new AdminBlogCategoryResource($category->loadCount('blogs')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'category';
        $slug = $baseSlug;
        $suffix = 2;

        while (BlogCategory::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
