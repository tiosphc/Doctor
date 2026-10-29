<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = ServiceCategory::query()
            ->whereHas('services', fn (Builder $query): Builder => $query->active())
            ->withCount(['services' => fn (Builder $query): Builder => $query->active()])
            ->with(['services' => function (HasMany $query): void {
                $query->active()
                    ->with('category:id,name,slug')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->orderBy('id');
            }])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ServiceCategoryResource::collection($categories);
    }

    public function show(ServiceCategory $serviceCategory): ServiceCategoryResource
    {
        $this->loadActiveServices($serviceCategory);

        abort_unless($serviceCategory->services_count > 0, 404, 'Service category not found.');

        return new ServiceCategoryResource($serviceCategory);
    }

    public function showService(ServiceCategory $serviceCategory, string $service): ServiceResource
    {
        $serviceModel = Service::query()
            ->active()
            ->whereBelongsTo($serviceCategory, 'category')
            ->where('slug', $service)
            ->with('category:id,name,slug')
            ->withCount('publishedReviews')
            ->withAvg('publishedReviews', 'rating')
            ->firstOrFail();

        return new ServiceResource($serviceModel);
    }

    private function loadActiveServices(ServiceCategory $serviceCategory): void
    {
        $serviceCategory->loadCount(['services' => fn (Builder $query): Builder => $query->active()]);
        $serviceCategory->load(['services' => function (HasMany $query): void {
            $query->active()
                ->with('category:id,name,slug')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->orderBy('id');
        }]);
    }
}
