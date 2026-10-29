<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductPricingRequest;
use App\Models\Product;
use App\Services\ProductPricingService;
use Illuminate\Http\JsonResponse;

class ProductPricingController extends Controller
{
    public function show(Product $product, ProductPricingService $pricing): JsonResponse
    {
        return response()->json(['data' => $pricing->show($product)]);
    }

    public function update(SaveProductPricingRequest $request, Product $product, ProductPricingService $pricing): JsonResponse
    {
        $pricing->save($product, $request->validated(), $request->user()->id);

        return response()->json(['data' => $pricing->show($product->refresh())]);
    }
}
