<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $services = Service::query()
            ->with('category:id,name,slug')
            ->withCount('publishedReviews')
            ->withAvg('publishedReviews', 'rating')
            ->active();

        if (isset($validated['search'])) {
            $search = $validated['search'];

            $services->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('category', function (Builder $query) use ($search): void {
                        $query->where('name', 'like', '%'.$search.'%');
                    });
            });
        }

        return ServiceResource::collection(
            $services->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(15)->withQueryString(),
        );
    }

    public function show(string $service): ServiceResource
    {
        $serviceModel = Service::query()
            ->active()
            ->where(function (Builder $query) use ($service): void {
                $query->where('slug', $service);

                if (ctype_digit($service)) {
                    $query->orWhereKey((int) $service);
                }
            })
            ->with('category:id,name,slug')
            ->withCount('publishedReviews')
            ->withAvg('publishedReviews', 'rating')
            ->firstOrFail();

        return new ServiceResource($serviceModel);
    }
}
