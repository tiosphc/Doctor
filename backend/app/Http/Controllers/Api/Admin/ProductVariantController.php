<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductVariantController extends Controller
{
    public function store(SaveProductVariantRequest $request, Product $product): JsonResponse
    {
        $variant = $product->variants()->create($request->validated());

        return response()->json(['data' => $variant->load('unit')], 201);
    }

    public function update(SaveProductVariantRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        abort_unless($variant->product_id === $product->id, 404);
        DB::transaction(function () use ($request, $variant): void {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $data = $request->validated();
            if ((isset($data['sku']) && $data['sku'] !== $locked->sku
                || isset($data['unit_id']) && (int) $data['unit_id'] !== $locked->unit_id
                || array_key_exists('track_inventory', $data) && (bool) $data['track_inventory'] !== $locked->track_inventory)
                && (DB::table('inventory_balances')->where('product_variant_id', $locked->id)->exists()
                    || DB::table('stock_movements')->where('product_variant_id', $locked->id)->exists())) {
                throw new HttpResponseException(response()->json([
                    'code' => 'INVENTORY_VARIANT_IDENTITY_IMMUTABLE',
                    'message' => 'SKU, unit and inventory tracking cannot change after stock history exists.',
                ], 409));
            }
            $locked->update($data);
        });

        return response()->json(['data' => $variant->refresh()->load('unit')]);
    }
}
