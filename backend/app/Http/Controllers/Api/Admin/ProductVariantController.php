<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantWriteService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class ProductVariantController extends Controller
{
    public function store(SaveProductVariantRequest $request, Product $product, ProductVariantWriteService $writer): JsonResponse
    {
        $variant = $writer->create($product, $request->validated());

        return response()->json(['data' => $variant->load('unit')], 201);
    }

    public function update(SaveProductVariantRequest $request, Product $product, ProductVariant $variant, ProductVariantWriteService $writer): JsonResponse
    {
        if (! $writer->update($product, $variant, $request->validated())) {
            throw new HttpResponseException(response()->json([
                'code' => 'INVENTORY_VARIANT_IDENTITY_IMMUTABLE',
                'message' => 'SKU, unit and inventory tracking cannot change after stock history exists.',
            ], 409));
        }

        return response()->json(['data' => $variant->refresh()->load('unit')]);
    }
}
