<?php

namespace App\Http\Controllers\Web\Retail;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitDealerApplicationRequest;
use App\Models\DealerAccountUser;
use App\Models\DealerApplication;
use App\Services\DealerApplicationService;
use App\Services\DealerContextService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DealerApplicationController extends Controller
{
    public function create(Request $request, DealerContextService $context): View
    {
        $application = $request->user()->dealerApplications()->latest('id')->first();
        $hasActiveMembership = $request->user()->dealerMemberships()
            ->where('status', DealerAccountUser::STATUS_ACTIVE)->exists();

        return view('retail.dealer-applications.create', [
            'application' => $application,
            'canApply' => ! $hasActiveMembership && ($application === null || in_array($application->status, [
                DealerApplication::STATUS_REJECTED, DealerApplication::STATUS_CANCELLED,
            ], true)),
            'portalAvailable' => $context->primaryAccount($request->user()) !== null,
        ]);
    }

    public function store(SubmitDealerApplicationRequest $request, DealerApplicationService $service): RedirectResponse
    {
        try {
            $application = $service->submit($request->user(), $request->validated());
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }

            $code = $exception->getResponse()->getData(true)['code'] ?? null;
            $message = match ($code) {
                'DEALER_APPLICATION_PENDING' => 'Bạn đã có đơn đăng ký đang chờ xét duyệt.',
                'DEALER_MEMBERSHIP_EXISTS' => 'Tài khoản đã có quyền đại lý. Không thể gửi thêm đơn đăng ký.',
                'DEALER_APPLICANT_INELIGIBLE' => 'Chỉ tài khoản khách hàng mới có thể đăng ký đại lý.',
                default => 'Không thể gửi đơn đăng ký lúc này. Vui lòng kiểm tra trạng thái và thử lại.',
            };

            throw ValidationException::withMessages(['application' => $message]);
        }

        return redirect()->route('retail.web.dealer-applications.show', $application)
            ->with('success', 'Đã gửi đơn đăng ký. Vui lòng theo dõi trạng thái xét duyệt tại đây.');
    }

    public function current(Request $request, DealerContextService $context): View|RedirectResponse
    {
        $application = $request->user()->dealerApplications()->latest('id')->first();
        if ($application === null) {
            return redirect()->route('retail.web.dealer-applications.create');
        }

        return $this->detail($application, $context->primaryAccount($request->user()) !== null);
    }

    public function show(Request $request, DealerApplication $application, DealerContextService $context): View
    {
        abort_unless($application->user_id === $request->user()->id, 404);

        return $this->detail($application, $context->primaryAccount($request->user()) !== null);
    }

    private function detail(DealerApplication $application, bool $portalAvailable): View
    {
        return view('retail.dealer-applications.show', [
            'application' => $application,
            'portalAvailable' => $portalAvailable,
        ]);
    }
}
