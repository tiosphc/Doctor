<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\Doctor;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'service_id' => [
                Rule::requiredIf($request->boolean('booking')),
                'nullable',
                'integer',
                'exists:services,id',
            ],
            'booking' => ['nullable', 'boolean'],
        ]);

        $doctors = Doctor::query()
            ->active()
            ->withCount('publishedReviews')
            ->withSum('publishedReviews', 'rating');

        if (isset($validated['search'])) {
            $search = $validated['search'];

            $doctors->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('specialty', 'like', '%'.$search.'%');
            });
        }

        if (isset($validated['service_id'])) {
            $serviceId = $validated['service_id'];

            $doctors->whereHas('services', function (Builder $query) use ($serviceId): void {
                $query->active()->whereKey($serviceId);
            });
        }

        if ($request->boolean('booking')) {
            $doctors->whereHas('schedules');
        }

        return DoctorResource::collection(
            $doctors->orderBy('name')->orderBy('id')->paginate(15)->withQueryString(),
        );
    }

    public function show(Doctor $doctor): DoctorResource
    {
        abort_unless($doctor->status === Doctor::STATUS_ACTIVE, 404, 'Doctor not found.');

        $doctor->load([
            'services' => fn (BelongsToMany $query): BelongsToMany => $query
                ->active()
                ->orderBy('name')
                ->orderBy('services.id'),
        ]);
        $doctor->loadCount('publishedReviews')->loadSum('publishedReviews', 'rating');
        $distribution = Review::query()->whereBelongsTo($doctor)->where('status', Review::STATUS_PUBLISHED)
            ->selectRaw('rating, COUNT(*) AS aggregate')->groupBy('rating')->pluck('aggregate', 'rating');
        $ratingDistribution = new \stdClass;
        foreach (range(1, 5) as $rating) {
            $ratingDistribution->{(string) $rating} = (int) ($distribution[$rating] ?? 0)
                + ($rating === 5 ? $doctor->baseline_review_count : 0);
        }
        $doctor->setAttribute('rating_distribution', $ratingDistribution);
        $doctor->services->load('category:id,name,slug');

        return new DoctorResource($doctor);
    }
}
