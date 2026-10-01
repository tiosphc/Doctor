<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\Product;
use App\Models\SalesPromotion;
use App\Models\SalesPromotionGiftRule;
use App\Models\Warehouse;
use App\Services\DealerEffectivePricingService;
use App\Services\SalesGiftPromotionVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftPromotionCatalogController extends Controller
{
    public function retail(SalesGiftPromotionVisibilityService $visibility): JsonResponse
    {
        return response()->json(['data' => $this->listing($visibility, 'retail')]);
    }

    public function dealer(Request $request, DealerAccount $dealer, DealerEffectivePricingService $pricing,
        SalesGiftPromotionVisibilityService $visibility, DealerProductController $catalog): JsonResponse
    {
        $context = $pricing->context($request->user(), $dealer);
        $products = $catalog->visible($context['tier']->id)->get();
        $prices = $pricing->catalogPrices($context, $products->flatMap(fn (Product $product) => $product->variants));
        $products = $products->filter(fn (Product $product): bool => $product->variants
            ->contains(fn ($variant): bool => isset($prices[$variant->id])));
        $eligibleIds = $products->pluck('id')->all();
        $pricedVariantIds = array_keys($prices);
        $gifts = $this->listing($visibility, 'dealer', $context['tier']->id, $eligibleIds, $pricedVariantIds);
        $now = now();
        $promotions = SalesPromotion::query()->with(['targets', 'dealerTiers'])->where('status', 'active')
            ->where('discount_type', '<>', 'buy_a_get_b')->whereIn('sales_scope', ['dealer', 'both'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('id')->get();
        $offers = [];
        foreach ($promotions as $promotion) {
            if ($promotion->dealerTiers->isNotEmpty() && ! $promotion->dealerTiers->contains('id', $context['tier']->id)) {
                continue;
            }
            $matched = $products->first(function (Product $product) use ($promotion): bool {
                return $promotion->targets->isEmpty() || $promotion->targets->contains(fn ($target): bool => $target->product_id === $product->id || $target->product_category_id === $product->product_category_id);
            });
            if ($matched === null) {
                continue;
            }
            $offers[] = ['code' => $promotion->code, 'name' => $promotion->name,
                'discount_type' => $promotion->discount_type, 'discount_value' => $promotion->discount_value,
                'buy_product_id' => $matched->id, 'buy_product_name' => $matched->name,
                'buy_product_slug' => $matched->slug, 'ends_at' => $promotion->ends_at,
                'dealer_tiers' => $promotion->dealerTiers->pluck('name')->all(),
                'buy_available' => $this->inStock($matched->variants->pluck('id')->intersect($pricedVariantIds)->all())];
        }

        return response()->json(['data' => array_merge($gifts, $offers)]);
    }

    /** @return list<array<string, mixed>> */
    private function listing(SalesGiftPromotionVisibilityService $visibility, string $channel,
        ?int $tierId = null, ?array $eligibleIds = null, ?array $pricedVariantIds = null): array
    {
        if (! $visibility->isAvailable()) {
            return [];
        }
        $productIds = SalesPromotionGiftRule::query()->distinct()->pluck('buy_product_id');
        if ($eligibleIds !== null) {
            $productIds = $productIds->intersect($eligibleIds);
        }
        $products = Product::query()->whereIn('id', $productIds)->where('status', 'active')
            ->where('gift_only', false)->get(['id', 'name', 'slug']);
        $summaries = $visibility->forProducts($products->pluck('id')->all(), $channel, $tierId);
        $listing = [];
        foreach ($products as $product) {
            foreach ($summaries[$product->id] ?? [] as $summary) {
                $listing[] = ['buy_product_id' => $product->id, 'buy_product_name' => $product->name,
                    'buy_product_slug' => $product->slug, 'discount_type' => 'buy_a_get_b',
                    'buy_available' => $this->inStock($product->variants()->pluck('id')
                        ->when($pricedVariantIds !== null, fn ($ids) => $ids->intersect($pricedVariantIds))->all()), ...$summary];
            }
        }

        return $listing;
    }

    /** @param list<int> $variantIds */
    private function inStock(array $variantIds): bool
    {
        $warehouseId = Warehouse::query()->where('status', 'active')->where('is_default_sales', true)->value('id');
        if ($warehouseId === null) {
            return false;
        }

        return DB::table('inventory_balances')->whereIn('product_variant_id', $variantIds)
            ->where('warehouse_id', $warehouseId)
            ->whereRaw('on_hand_quantity > reserved_quantity')->exists();
    }
}
