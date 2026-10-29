<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogDetailResource;
use App\Http\Resources\BlogResource;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:15'],
        ]);

        $blogs = Blog::query()
            ->published()
            ->select(['id', 'author_id', 'title', 'slug', 'category_id', 'excerpt', 'image', 'published_at'])
            ->with(['author:id,name', 'category:id,name'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 9)
            ->withQueryString();

        return BlogResource::collection($blogs);
    }

    public function show(Blog $blog): BlogDetailResource
    {
        abort_unless($blog->published_at !== null && $blog->published_at->isPast(), 404, 'Blog not found.');

        return new BlogDetailResource($blog->load(['author:id,name', 'category:id,name']));
    }
}
