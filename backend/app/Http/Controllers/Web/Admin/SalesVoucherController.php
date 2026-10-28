<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSalesVoucherRequest;
use App\Models\SalesVoucher;
use App\Services\SalesVoucherWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesVoucherController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'upcoming', 'expired'])],
        ]);
        $at = now();
        $query = SalesVoucher::query()
            ->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($nested) => $nested->where('normalized_code', 'like', '%'.Str::upper(trim($search)).'%')
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
            });

        return view('admin.sales-vouchers.index', [
            'vouchers' => $query->latest('id')->paginate(20)->withQueryString(), 'filters' => $filters,
        ]);
    }

    public function create(SalesVoucherWriteService $writer): View
    {
        return view('admin.sales-vouchers.form', ['voucher' => null, 'suggestedCode' => $writer->suggestCode()]);
    }

    public function store(SaveSalesVoucherRequest $request, SalesVoucherWriteService $writer): RedirectResponse
    {
        $voucher = $writer->create($request->validated(), $request->user());

        return redirect()->route('admin.web.sales-vouchers.show', $voucher)->with('success', 'Đã tạo voucher bán hàng.');
    }

    public function show(SalesVoucher $voucher): View
    {
        $voucher->loadCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')]);

        return view('admin.sales-vouchers.show', [
            'voucher' => $voucher,
            'redemptions' => $voucher->redemptions()
                ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sales_voucher_redemptions.sales_order_id')
                ->select('sales_voucher_redemptions.*', 'sales_orders.order_code')
                ->orderByDesc('sales_voucher_redemptions.id')->paginate(20),
        ]);
    }

    public function edit(SalesVoucher $voucher): View
    {
        return view('admin.sales-vouchers.form', ['voucher' => $voucher, 'suggestedCode' => null]);
    }

    public function update(SaveSalesVoucherRequest $request, SalesVoucher $voucher, SalesVoucherWriteService $writer): RedirectResponse
    {
        $writer->update($voucher, $request->validated());

        return redirect()->route('admin.web.sales-vouchers.show', $voucher)->with('success', 'Đã cập nhật voucher bán hàng.');
    }
}
