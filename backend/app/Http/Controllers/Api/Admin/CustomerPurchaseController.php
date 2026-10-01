<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherResource;
use App\Models\Customer;
use App\Models\Voucher;
use App\Services\OrderPaymentSummaryService;
use App\Services\RetailCustomerPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPurchaseController extends Controller
{
    public function show(Customer $customer, RetailCustomerPurchaseService $purchases, OrderPaymentSummaryService $payments): array
    {
        return ['data' => $purchases->summary($customer, $payments)];
    }

    public function products(Request $request, Customer $customer, RetailCustomerPurchaseService $purchases): AnonymousResourceCollection
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return JsonResource::collection($purchases->products($customer, (int) ($validated['page'] ?? 1))
            ->through(fn ($product): array => (array) $product));
    }

    public function vouchers(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $vouchers = Voucher::query()->where('status', Voucher::STATUS_ACTIVE)->where('expires_at', '>=', now())
            ->where('user_id', $customer->user_id)
            ->when($customer->user_id === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->latest('created_at')->orderByDesc('id')->paginate(20, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return VoucherResource::collection($vouchers);
    }
}
