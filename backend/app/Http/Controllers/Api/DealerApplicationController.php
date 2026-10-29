<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitDealerApplicationRequest;
use App\Http\Resources\DealerApplicationResource;
use App\Models\DealerApplication;
use App\Services\DealerApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DealerApplicationController extends Controller
{
    public function store(SubmitDealerApplicationRequest $request, DealerApplicationService $service): JsonResponse
    {
        $application = $service->submit($request->user(), $request->validated());

        return (new DealerApplicationResource($application))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function mine(Request $request): DealerApplicationResource
    {
        $application = $request->user()->dealerApplications()->with('approvedAccount')->latest('id')->firstOrFail();

        return new DealerApplicationResource($application);
    }

    public function show(Request $request, DealerApplication $application): DealerApplicationResource
    {
        abort_unless($application->user_id === $request->user()->id, 404);

        return new DealerApplicationResource($application->load('approvedAccount'));
    }
}
