<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ErpReportRequest;
use App\Services\ErpReportService;
use App\Support\ReportPeriod;
use Illuminate\View\View;

class ReportController extends Controller
{
    /** @var array<string, string> */
    private const REPORTS = [
        'sales' => 'Doanh thu',
        'orders' => 'Đơn bán hàng',
        'products' => 'Sản phẩm',
        'inventory' => 'Tồn kho',
        'procurement' => 'Mua hàng',
        'dealers' => 'Đại lý',
        'wallets' => 'Ví đại lý',
        'promotions' => 'Khuyến mãi',
    ];

    public function index(ErpReportRequest $request, ErpReportService $reports): View
    {
        $filters = $request->validated();
        $data = $reports->overview(new ReportPeriod($filters));

        return view('admin.reports.index', [
            'data' => $data,
            'filters' => $filters,
            'reportNames' => self::REPORTS,
        ]);
    }

    public function show(ErpReportRequest $request, string $report, ErpReportService $reports): View
    {
        abort_unless(isset(self::REPORTS[$report]), 404);

        $filters = $request->validated();
        $period = new ReportPeriod($filters);
        $data = match ($report) {
            'sales' => $reports->sales($period),
            'orders' => $reports->orders($period),
            'products' => $reports->products($period),
            'inventory' => $reports->inventory($period),
            'procurement' => $reports->procurement($period),
            'dealers' => $reports->dealers($period),
            'wallets' => $reports->wallets($period),
            'promotions' => $reports->promotions($period),
        };

        return view('admin.reports.show', [
            'data' => $data,
            'filters' => $filters,
            'report' => $report,
            'reportTitle' => self::REPORTS[$report],
            'reportNames' => self::REPORTS,
        ]);
    }
}
