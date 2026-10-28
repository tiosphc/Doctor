<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectDealerApplicationRequest;
use App\Models\AuditLog;
use App\Models\DealerApplication;
use App\Services\DealerApplicationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DealerApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
            'applicant_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = DealerApplication::query()->with(['user:id,name,email', 'approvedAccount:id,code,source_application_id']);
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['applicant_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('company_name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
        }

        return view('admin.dealer-applications.index', [
            'applications' => $query->latest('id')->paginate(15)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(DealerApplication $application): View
    {
        $application->load(['user:id,name,email', 'reviewer:id,name', 'approvedAccount:id,code,source_application_id']);

        return view('admin.dealer-applications.show', [
            'application' => $application,
            'history' => AuditLog::query()->where('target_type', 'DealerApplication')->where('target_id', $application->id)
                ->latest('id')->limit(20)->get(),
        ]);
    }

    public function approve(Request $request, DealerApplication $application, DealerApplicationService $service): RedirectResponse
    {
        $this->runDomain(fn () => $service->approve($application, $request->user()));

        return redirect()->route('admin.web.dealer-applications.show', $application)->with('success', 'Đã duyệt đơn đăng ký đại lý.');
    }

    public function reject(RejectDealerApplicationRequest $request, DealerApplication $application, DealerApplicationService $service): RedirectResponse
    {
        $this->runDomain(fn () => $service->reject($application, $request->user(), $request->validated('rejection_reason')));

        return redirect()->route('admin.web.dealer-applications.show', $application)->with('success', 'Đã từ chối đơn đăng ký đại lý.');
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_APPLICATION_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể xét duyệt ('.$code.'). Hãy tải lại trạng thái đơn.']);
        }
    }
}
