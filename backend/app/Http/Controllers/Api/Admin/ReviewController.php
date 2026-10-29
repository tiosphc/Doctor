<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewIndexRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(ReviewIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $reviews = Review::query()->with(['user:id,name', 'doctor:id,name', 'service:id,name'])
            ->when($validated['rating'] ?? null, fn (Builder $query, int $rating): Builder => $query->where('rating', $rating))
            ->when($validated['doctor_id'] ?? null, fn (Builder $query, int $doctorId): Builder => $query->where('doctor_id', $doctorId))
            ->when($validated['service_id'] ?? null, fn (Builder $query, int $serviceId): Builder => $query->where('service_id', $serviceId))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->latest('created_at')->orderByDesc('id')->paginate(10)->withQueryString();

        return ReviewResource::collection($reviews);
    }

    public function update(Request $request, Review $review): ReviewResource
    {
        $validated = $request->validate(['status' => ['required', Rule::in(Review::STATUSES)]]);
        DB::transaction(function () use ($review, $validated, $request): void {
            $previousStatus = $review->status;
            $review->update(['status' => $validated['status']]);

            if ($previousStatus !== $review->status) {
                $action = $review->status === Review::STATUS_PUBLISHED
                    ? AuditLogger::ACTION_ACTIVATE
                    : AuditLogger::ACTION_DEACTIVATE;
                $this->auditLogger->log(
                    $action,
                    AuditLogger::MODULE_REVIEW,
                    $review,
                    "{$request->user()->name} đã thay đổi trạng thái đánh giá #{$review->id}.",
                    oldValues: ['status' => $previousStatus],
                    newValues: ['status' => $review->status],
                    targetName: 'Đánh giá #'.$review->id,
                );
            }
        });

        return new ReviewResource($review->refresh()->load(['user:id,name', 'doctor:id,name', 'service:id,name']));
    }
}
