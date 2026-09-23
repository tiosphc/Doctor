<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Doctor;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicReviewController extends Controller
{
    public function doctor(Doctor $doctor): AnonymousResourceCollection
    {
        abort_unless($doctor->status === Doctor::STATUS_ACTIVE, 404);

        return ReviewResource::collection($doctor->reviews()->where('status', Review::STATUS_PUBLISHED)
            ->with('user:id,name')->latest('created_at')->orderByDesc('id')->paginate(10)->withQueryString());
    }

    public function service(Service $service): AnonymousResourceCollection
    {
        abort_unless($service->status === Service::STATUS_ACTIVE, 404);

        return ReviewResource::collection($service->reviews()->where('status', Review::STATUS_PUBLISHED)
            ->with('user:id,name')->latest('created_at')->orderByDesc('id')->paginate(10)->withQueryString());
    }
}
