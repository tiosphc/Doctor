<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RetailCartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailCartController extends Controller
{
    public function show(Request $request, RetailCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->show($request->user()->id)]);
    }

    public function add(Request $request, RetailCartService $carts): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $carts->add($request->user()->id, $data['product_variant_id'], $data['quantity'])]);
    }

    public function update(Request $request, int $cartItem, RetailCartService $carts): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $carts->update($request->user()->id, $cartItem, $data['quantity'])]);
    }

    public function remove(Request $request, int $cartItem, RetailCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->remove($request->user()->id, $cartItem)]);
    }

    public function clear(Request $request, RetailCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->clear($request->user()->id)]);
    }

    public function voucher(Request $request, RetailCartService $carts): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:32']]);

        return response()->json(['data' => $carts->applyVoucher($request->user()->id, $data['code'] ?? null)]);
    }
}
