<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceCategoryRequest;
use App\Http\Resources\AdminServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ServiceCategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $categories = ServiceCategory::query()
            ->withCount('services')
            ->orderBy('name')
            ->orderBy('id');

        $results = isset($validated['per_page'])
            ? $categories->paginate($validated['per_page'])->withQueryString()
            : $categories->get();

        return AdminServiceCategoryResource::collection($results);
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $heroImagePath = $this->storeUploadedImage($validated['hero_image'] ?? null);

        if ($heroImagePath !== null) {
            $validated['hero_image'] = $heroImagePath;
        }

        try {
            $category = DB::transaction(fn (): ServiceCategory => ServiceCategory::create([
                ...$validated,
                'slug' => $this->uniqueSlug($validated['name']),
            ]));
        } catch (Throwable $exception) {
            $this->deleteLocalImage($heroImagePath);

            throw $exception;
        }

        return (new AdminServiceCategoryResource($category->loadCount('services')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'category';
        $slug = $baseSlug;
        $suffix = 2;

        while (ServiceCategory::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function storeUploadedImage(mixed $image): ?string
    {
        if (! $image instanceof UploadedFile) {
            return null;
        }

        $path = $image->store('service-categories', 'public');

        if (! is_string($path)) {
            throw new \RuntimeException('Unable to store the service category image.');
        }

        return $path;
    }

    private function deleteLocalImage(?string $image): void
    {
        if ($image === null || filter_var($image, FILTER_VALIDATE_URL) !== false || str_starts_with($image, '//')) {
            return;
        }

        Storage::disk('public')->delete($image);
    }
}
