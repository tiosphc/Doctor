<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Services\AdminDashboardService;
use App\Services\ErpReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        DashboardRequest $request,
        AdminDashboardService $dashboardService,
        ErpReportService $erpReportService,
    ): View {
        $period = $request->validated()['period'] ?? AdminDashboardService::PERIOD_SEVEN_DAYS;
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $reportPeriod = new ReportPeriod([
            'from' => $today->subDays(29)->toDateString(),
            'to' => $today->toDateString(),
            'channel' => 'all',
        ]);

        return view('admin.dashboard.index', [
            'dashboard' => $dashboardService->summarize($period),
            'erp' => $erpReportService->overview($reportPeriod),
            'period' => $period,
            'reactBaseUrl' => rtrim((string) config('app.frontend_url'), '/'),
            'reactAdminUrl' => rtrim((string) config('app.frontend_url'), '/').'/admin',
        ]);
    }
}
