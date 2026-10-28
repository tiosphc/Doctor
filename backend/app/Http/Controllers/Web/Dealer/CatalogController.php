<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\DealerCatalogReadService;
use App\Services\DealerContextService;
use App\Services\DealerEffectivePricingService;
use App\Services\DealerTierService;
use App\Services\SalesPromotionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerCatalogReadService $catalog,
        DealerEffectivePricingService $pricing, SalesPromotionService $promotions, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);
        $result = $catalog->index($request, $account, $pricing, $promotions);

        return view('dealer.products.index', [
            'products' => $result['data'], 'meta' => $result['meta'],
            'search' => (string) $request->query('search', ''),
            ...$this->layout($account, $tiers),
        ]);
    }

    public function show(Request $request, string $product, DealerContextService $context,
        DealerCatalogReadService $catalog, DealerEffectivePricingService $pricing,
        SalesPromotionService $promotions, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);
        $result = $catalog->show($request, $account, $product, $pricing, $promotions);
        $orderableVariantIds = ProductVariant::query()
            ->whereIn('id', array_column($result['data']['variants'], 'id'))
            ->where('track_inventory', true)->pluck('id')->all();

        return view('dealer.products.show', [
            'product' => $result['data'], 'orderableVariantIds' => $orderableVariantIds,
            ...$this->layout($account, $tiers),
        ]);
    }

    private function account(Request $request, DealerContextService $context): DealerAccount
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tài khoản đại lý đang hoạt động.');

        return $account;
    }

    /** @return array<string, mixed> */
    private function layout(DealerAccount $account, DealerTierService $tiers): array
    {
        $account->load('wallet');

        return ['account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier']];
    }
}
