<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogIndexRequest;
use App\Http\Requests\Admin\StoreBlogRequest;
use App\Http\Resources\AdminBlogDetailResource;
use App\Http\Resources\AdminBlogResource;
use App\Models\Blog;
use App\Services\AuditLogger;
use App\Support\PublicImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BlogController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(BlogIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $blogs = Blog::query()
            ->select(['id', 'author_id', 'title', 'slug', 'category_id', 'excerpt', 'image', 'published_at', 'created_at'])
            ->with(['author:id,name', 'category:id,name'])
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('category', function (Builder $query) use ($search): void {
                            $query->where('name', 'like', '%'.$search.'%');
                        })
                        ->orWhere('excerpt', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 5)
            ->withQueryString();

        return AdminBlogResource::collection($blogs);
    }

    public function store(StoreBlogRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $imagePath = ($validated['image'] ?? null) instanceof UploadedFile
            ? PublicImage::store($validated['image'], 'blogs')
            : null;

        if ($imagePath !== null) {
            $validated['image'] = $imagePath;
        }

        try {
            $blog = DB::transaction(function () use ($validated, $request): Blog {
                $blog = Blog::create([
                    ...$validated,
                    'author_id' => $request->user()->id,
                    'slug' => $this->uniqueSlug($validated['title']),
                    'published_at' => now(),
                ]);
                $this->auditLogger->log(
                    AuditLogger::ACTION_CREATE,
                    AuditLogger::MODULE_BLOG,
                    $blog,
                    "{$request->user()->name} đã tạo bài viết {$blog->title}.",
                    newValues: $blog->only(['title', 'category_id', 'published_at']),
                );

                return $blog;
            });
        } catch (Throwable $exception) {
            PublicImage::delete($imagePath);

            throw $exception;
        }

        return (new AdminBlogDetailResource($blog->load(['author:id,name', 'category:id,name'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function uniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title) ?: 'blog';
        $slug = $baseSlug;
        $suffix = 2;

        while (Blog::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
