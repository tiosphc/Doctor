<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(
        DashboardRequest $request,
        AdminDashboardService $dashboardService,
    ): JsonResponse {
        $period = $request->validated()['period'] ?? AdminDashboardService::PERIOD_SEVEN_DAYS;

        return response()->json($dashboardService->summarize($period));
    }
}
