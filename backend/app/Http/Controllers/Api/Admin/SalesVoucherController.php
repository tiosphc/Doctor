<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSalesVoucherRequest;
use App\Models\SalesVoucher;
use App\Services\SalesVoucherWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesVoucherController extends Controller
{
    public function generateCode(SalesVoucherWriteService $writer): JsonResponse
    {
        return response()->json(['code' => $writer->suggestCode()]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,inactive,upcoming,expired'],
        ]);
        $at = now();
        $vouchers = SalesVoucher::query()
            ->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($nested) => $nested->where('normalized_code', 'like', '%'.mb_strtoupper($search).'%')
                    ->orWhere('name', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, function ($query, $status) use ($at): void {
                if ($status === 'inactive') {
                    $query->where('status', 'inactive');
                } elseif ($status === 'upcoming') {
                    $query->where('status', 'active')->where('starts_at', '>', $at);
                } elseif ($status === 'expired') {
                    $query->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<', $at);
                } else {
                    $query->where('status', 'active')
                        ->where(fn ($nested) => $nested->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                        ->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
                }
            })
            ->latest('id')->paginate(20);

        return response()->json($vouchers);
    }

    public function show(SalesVoucher $voucher): JsonResponse
    {
        return response()->json(['data' => $voucher]);
    }

    public function store(SaveSalesVoucherRequest $request, SalesVoucherWriteService $writer): JsonResponse
    {
        $voucher = $writer->create($request->validated(), $request->user());

        return response()->json(['data' => $voucher], 201);
    }

    public function update(SaveSalesVoucherRequest $request, SalesVoucher $voucher, SalesVoucherWriteService $writer): JsonResponse
    {
        $voucher = $writer->update($voucher, $request->validated());

        return response()->json(['data' => $voucher]);
    }
}
