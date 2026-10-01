<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Services\DealerEffectivePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerPriceQuoteController extends Controller
{
    public function __invoke(Request $request, DealerAccount $dealer, DealerEffectivePricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['prohibited'],
            'tier_id' => ['prohibited'], 'unit_price' => ['prohibited'],
            'currency' => ['prohibited'], 'price_list_id' => ['prohibited'],
        ]);

        return response()->json(['data' => $pricing->quote($request->user(), $dealer,
            (int) $data['product_variant_id'], (string) $data['quantity'])]);
    }
}
