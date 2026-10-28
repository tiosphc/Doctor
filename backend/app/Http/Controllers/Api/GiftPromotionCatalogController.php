<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Services\DealerCatalogReadService;
use App\Services\DealerEffectivePricingService;
use App\Services\GiftPromotionCatalogService;
use App\Services\SalesGiftPromotionVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftPromotionCatalogController extends Controller
{
    public function __construct(private readonly GiftPromotionCatalogService $catalog) {}

    public function retail(SalesGiftPromotionVisibilityService $visibility): JsonResponse
    {
        return response()->json(['data' => $this->catalog->retail($visibility)]);
    }

    public function dealer(Request $request, DealerAccount $dealer, DealerEffectivePricingService $pricing,
        SalesGiftPromotionVisibilityService $visibility, DealerCatalogReadService $products): JsonResponse
    {
        return response()->json(['data' => $this->catalog->dealer($request, $dealer, $pricing, $visibility, $products)]);
    }
}
