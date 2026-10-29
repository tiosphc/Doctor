<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RetailCheckoutRequest;
use App\Http\Resources\RetailSalesOrderResource;
use App\Services\RetailCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailCheckoutController extends Controller
{
    public function review(Request $request, RetailCheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $checkout->review($request->user()->id)]);
    }

    public function store(RetailCheckoutRequest $request, RetailCheckoutService $checkout): RetailSalesOrderResource
    {
        $order = $checkout->checkout($request->user()->id, $request->validated());

        return new RetailSalesOrderResource($order->load(['items', 'promotionRedemptions.promotion:id,name']));
    }
}
