<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ErpReportRequest;
use App\Services\ErpReportService;
use App\Support\ReportPeriod;
use Illuminate\Http\JsonResponse;

class ErpReportController extends Controller
{
    public function __construct(private readonly ErpReportService $reports) {}

    private function period(ErpReportRequest $request): ReportPeriod
    {
        return new ReportPeriod($request->validated());
    }

    public function overview(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->overview($this->period($request)));
    }

    public function sales(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->sales($this->period($request)));
    }

    public function orders(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->orders($this->period($request)));
    }

    public function products(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->products($this->period($request)));
    }

    public function inventory(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->inventory($this->period($request)));
    }

    public function procurement(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->procurement($this->period($request)));
    }

    public function dealers(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->dealers($this->period($request)));
    }

    public function wallets(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->wallets($this->period($request)));
    }

    public function promotions(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->promotions($this->period($request)));
    }

    public function clinic(ErpReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->clinic($this->period($request)));
    }
}
