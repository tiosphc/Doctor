<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerTier;
use App\Models\ProductVariant;
use App\Services\ProductPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogPricingController extends Controller
{
    public function retailIndex(Request $request, ProductPricingService $pricing): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'price_status' => ['nullable', Rule::in(['priced', 'unpriced'])],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($pricing->catalog('retail', $filters));
    }

    public function dealerIndex(Request $request, ProductPricingService $pricing): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'price_status' => ['nullable', Rule::in(['priced', 'unpriced'])],
            'tier_id' => ['nullable', 'integer', Rule::exists('dealer_tiers', 'id')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($pricing->catalog('dealer', $filters));
    }

    public function retailUpdate(Request $request, ProductVariant $variant, ProductPricingService $pricing): JsonResponse
    {
        $data = $request->validate(['unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2']]);
        $pricing->updateRetail($variant, (string) $data['unit_price'], $request->user()->id);

        return response()->json(['data' => ['variant_id' => $variant->id]]);
    }

    public function dealerUpdate(Request $request, ProductVariant $variant, DealerTier $tier, ProductPricingService $pricing): JsonResponse
    {
        abort_unless($tier->status === 'active', 422);
        $data = $request->validate([
            'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'minimum_quantity' => ['required', 'integer', 'min:1'],
        ]);
        $pricing->updateDealer($variant, $tier, (string) $data['unit_price'], (int) $data['minimum_quantity'], $request->user()->id);

        return response()->json(['data' => ['variant_id' => $variant->id, 'tier_id' => $tier->id]]);
    }

    public function dealerDelete(Request $request, ProductVariant $variant, DealerTier $tier, ProductPricingService $pricing): JsonResponse
    {
        $pricing->updateDealer($variant, $tier, null, 1, $request->user()->id);

        return response()->json(['data' => ['variant_id' => $variant->id, 'tier_id' => $tier->id]]);
    }
}
