<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DealerShippingAddressRequest;
use App\Models\DealerAccount;
use App\Models\DealerShippingAddress;
use App\Services\DealerContextService;
use App\Services\DealerShippingAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerShippingAddressController extends Controller
{
    public function index(Request $request, DealerAccount $dealer, DealerContextService $context,
        DealerShippingAddressService $addresses): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(['data' => $addresses->forAccount($dealer)]);
    }

    public function store(Request $request, DealerAccount $dealer, DealerContextService $context,
        DealerShippingAddressService $addresses): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(['data' => $addresses->create($dealer,
            $request->validate(DealerShippingAddressRequest::rulesFor($request->input('province_code'))))], 201);
    }

    public function update(Request $request, DealerAccount $dealer, DealerShippingAddress $address,
        DealerContextService $context, DealerShippingAddressService $addresses): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless($address->dealer_account_id === $dealer->id, 404);

        return response()->json(['data' => $addresses->update($dealer, $address->id,
            $request->validate(DealerShippingAddressRequest::rulesFor($request->input('province_code'))))]);
    }

    public function destroy(Request $request, DealerAccount $dealer, DealerShippingAddress $address,
        DealerContextService $context, DealerShippingAddressService $addresses): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        $addresses->delete($dealer, $address->id);

        return response()->json(['message' => 'Đã xóa địa chỉ giao hàng.']);
    }
}
