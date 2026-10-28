<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Services\DealerCatalogReadService;
use App\Services\DealerEffectivePricingService;
use App\Services\SalesPromotionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerProductController extends Controller
{
    public function __construct(private readonly DealerCatalogReadService $catalog) {}

    public function index(Request $request, DealerAccount $dealer, DealerEffectivePricingService $pricing,
        SalesPromotionService $promotions): JsonResponse
    {
        return response()->json($this->catalog->index($request, $dealer, $pricing, $promotions));
    }

    public function show(Request $request, DealerAccount $dealer, string $product, DealerEffectivePricingService $pricing,
        SalesPromotionService $promotions): JsonResponse
    {
        return response()->json($this->catalog->show($request, $dealer, $product, $pricing, $promotions));
    }

    public function visible(int $tierId): Builder
    {
        return $this->catalog->visible($tierId);
    }
}
