<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectDealerApplicationRequest;
use App\Http\Resources\DealerApplicationResource;
use App\Models\DealerApplication;
use App\Services\DealerApplicationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DealerApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
            'applicant_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = DealerApplication::query()->with(['user:id,name,email', 'approvedAccount:id,code,source_application_id']);
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['applicant_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id));
        $query->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date));
        $query->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('company_name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
        }

        return DealerApplicationResource::collection($query->latest('id')->paginate(15)->withQueryString());
    }

    public function show(DealerApplication $application): DealerApplicationResource
    {
        return new DealerApplicationResource($application->load(['user:id,name,email', 'reviewer:id,name', 'approvedAccount:id,code,source_application_id']));
    }

    public function approve(Request $request, DealerApplication $application, DealerApplicationService $service): DealerApplicationResource
    {
        return new DealerApplicationResource($service->approve($application, $request->user())->load(['user:id,name,email', 'reviewer:id,name']));
    }

    public function reject(RejectDealerApplicationRequest $request, DealerApplication $application, DealerApplicationService $service): DealerApplicationResource
    {
        return new DealerApplicationResource($service->reject($application, $request->user(), $request->validated('rejection_reason'))->load(['user:id,name,email', 'reviewer:id,name']));
    }
}
