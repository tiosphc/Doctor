<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\VoucherResource;
use App\Models\Appointment;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, Appointment $appointment, ReviewService $reviewService): JsonResponse
    {
        $result = $reviewService->create($appointment, $request->user(), $request->validated());
        $result->review->load(['user:id,name', 'doctor:id,name', 'service:id,name']);

        return response()->json([
            'data' => new ReviewResource($result->review),
            'voucher' => $result->voucher === null ? null : new VoucherResource($result->voucher),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateReviewRequest $request, Review $review, ReviewService $reviewService): ReviewResource
    {
        $updated = $reviewService->update($review, $request->user(), $request->validated());

        return new ReviewResource($updated->load(['user:id,name', 'doctor:id,name', 'service:id,name']));
    }
}
