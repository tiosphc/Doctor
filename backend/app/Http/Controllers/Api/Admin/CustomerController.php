<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerDetailResource;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\Customer;
use App\Services\AdminCustomerReadService;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(Request $request, AdminCustomerReadService $customers): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return CustomerResource::collection($customers->paginate($validated['search'] ?? null));
    }

    public function show(Customer $customer, LoyaltyService $loyaltyService, AdminCustomerReadService $customers): CustomerDetailResource
    {
        $detail = $customers->detail($customer, $loyaltyService);

        return new CustomerDetailResource($detail['customer'], $detail['loyalty'], $detail['availableVouchers']);
    }
}
