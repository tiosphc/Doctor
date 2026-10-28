<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Voucher;
use App\Services\AdminCustomerReadService;
use App\Services\LoyaltyService;
use App\Services\OrderPaymentSummaryService;
use App\Services\RetailCustomerPurchaseService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, AdminCustomerReadService $customers): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return view('admin.customers.index', [
            'customers' => $customers->paginate($filters['search'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function show(
        Request $request,
        Customer $customer,
        AdminCustomerReadService $customers,
        LoyaltyService $loyalty,
        RetailCustomerPurchaseService $purchases,
        OrderPaymentSummaryService $payments,
    ): View {
        $pages = $request->validate([
            'orders_page' => ['nullable', 'integer', 'min:1'],
            'products_page' => ['nullable', 'integer', 'min:1'],
            'vouchers_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $detail = $customers->detail($customer, $loyalty);
        $vouchers = Voucher::query()
            ->where('user_id', $customer->user_id)
            ->when($customer->user_id === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->latest('created_at')->orderByDesc('id')
            ->paginate(10, ['*'], 'vouchers_page', (int) ($pages['vouchers_page'] ?? 1));

        return view('admin.customers.show', [
            ...$detail,
            'purchaseSummary' => $purchases->summary($customer, $payments),
            'orders' => $purchases->history($customer, (int) ($pages['orders_page'] ?? 1)),
            'products' => $purchases->products($customer, (int) ($pages['products_page'] ?? 1), 'products_page'),
            'vouchers' => $vouchers,
        ]);
    }
}
