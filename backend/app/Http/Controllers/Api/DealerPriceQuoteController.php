<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Services\DealerEffectivePricingService;
use App\Services\SalesPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerPriceQuoteController extends Controller
{
    public function __invoke(Request $request, DealerAccount $dealer, DealerEffectivePricingService $pricing,
        SalesPromotionService $promotions): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['prohibited'],
            'tier_id' => ['prohibited'], 'unit_price' => ['prohibited'],
            'currency' => ['prohibited'], 'price_list_id' => ['prohibited'],
        ]);

        $quote = $pricing->quote($request->user(), $dealer,
            (int) $data['product_variant_id'], (string) $data['quantity']);
        $offers = $promotions->bestQuotes('dealer', $request->user()->id, $dealer->id,
            $quote['line_total'], [[
                'product_variant_id' => $quote['variant']['id'],
                'product_id' => $quote['product']['id'],
                'amount' => $quote['line_total'], 'quantity' => $quote['quantity'],
            ]], effectiveTierId: $quote['effective_tier']['id']);
        $discount = $offers['discount'];
        $discountAmount = $discount['discount_amount'] ?? '0.00';
        $quote['promotion'] = $discount === null ? null : [
            'code' => $discount['code'], 'name' => $discount['name'],
            'discount_type' => $discount['discount_type'],
            'discount_value' => $discount['discount_value'],
            'discount_amount' => $discountAmount,
        ];
        $quote['discounted_unit_price'] = bcsub($quote['unit_price'],
            bcdiv($discountAmount, $quote['quantity'], 2), 2);
        $quote['discounted_line_total'] = bcsub($quote['line_total'], $discountAmount, 2);

        return response()->json(['data' => $quote]);
    }
}
