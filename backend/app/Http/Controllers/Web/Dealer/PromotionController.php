<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DealerCatalogReadService;
use App\Services\DealerContextService;
use App\Services\DealerEffectivePricingService;
use App\Services\DealerTierService;
use App\Services\GiftPromotionCatalogService;
use App\Services\SalesGiftPromotionVisibilityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerEffectivePricingService $pricing,
        DealerTierService $tiers, GiftPromotionCatalogService $promotions,
        SalesGiftPromotionVisibilityService $visibility, DealerCatalogReadService $products): View
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tài khoản đại lý đang hoạt động.');
        $offers = $promotions->dealer($request, $account, $pricing, $visibility, $products);
        $account->load('wallet');

        return view('dealer.promotions.index', [
            'account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier'],
            'offers' => $offers,
        ]);
    }
}
