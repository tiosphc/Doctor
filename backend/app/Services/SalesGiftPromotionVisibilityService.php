<?php

namespace App\Services;

use App\Models\SalesPromotionGiftRule;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class SalesGiftPromotionVisibilityService
{
    public function isAvailable(): bool
    {
        return DB::table('migrations')
            ->where('migration', '2026_09_28_102009_add_buy_a_get_b_sales_promotions')->exists();
    }

    /** @param list<int> $productIds @return array<int, list<array<string, mixed>>> */
    public function forProducts(array $productIds, string $channel, ?int $effectiveTierId = null): array
    {
        if ($productIds === [] || ! $this->isAvailable()) {
            return [];
        }
        $now = now();
        $rules = SalesPromotionGiftRule::query()
            ->with(['promotion' => fn ($query) => $query->with('dealerTiers')
                ->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')]),
                'buyProduct', 'buyVariant', 'giftProduct.images', 'giftVariant'])
            ->whereIn('buy_product_id', $productIds)
            ->whereHas('promotion', fn ($query) => $query->effectiveAt($now)->forChannel($channel))
            ->orderBy('sales_promotion_id')
            ->get();
        if ($rules->isEmpty()) {
            return [];
        }
        $eligibleBuyVariants = DB::table('product_variants')->whereIn('product_id', $productIds)
            ->where('status', 'active')->where('track_inventory', true)
            ->where($channel === 'dealer' ? 'sellable_dealer' : 'sellable_retail', true)
            ->when($channel === 'dealer', function ($query) use ($effectiveTierId, $now): void {
                $query->whereExists(function ($subquery) use ($effectiveTierId, $now): void {
                    $subquery->selectRaw('1')->from('price_list_items as item')
                        ->join('price_lists as list', 'list.id', '=', 'item.price_list_id')
                        ->whereColumn('item.product_variant_id', 'product_variants.id')
                        ->where('item.status', 'active')->where('item.unit_price', '>', 0)
                        ->where('item.minimum_quantity', '>', 0)
                        ->where('list.status', 'active')->where('list.pricing_context', 'dealer')
                        ->where('list.scope_type', 'tier')->where('list.dealer_tier_id', $effectiveTierId)
                        ->where('list.currency', 'VND')
                        ->where(fn ($query) => $query->whereNull('item.effective_from')->orWhere('item.effective_from', '<=', $now))
                        ->where(fn ($query) => $query->whereNull('item.effective_to')->orWhere('item.effective_to', '>=', $now))
                        ->where(fn ($query) => $query->whereNull('list.effective_from')->orWhere('list.effective_from', '<=', $now))
                        ->where(fn ($query) => $query->whereNull('list.effective_to')->orWhere('list.effective_to', '>=', $now));
                });
            })
            ->get(['id', 'product_id']);
        $eligibleVariantIds = $eligibleBuyVariants->pluck('id')->all();
        $eligibleProductIds = $eligibleBuyVariants->pluck('product_id')->all();
        $warehouseId = Warehouse::query()->where('status', 'active')->where('is_default_sales', true)->value('id');
        $balances = $warehouseId === null ? collect() : DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)->whereIn('product_variant_id', $rules->pluck('gift_variant_id'))
            ->get()->keyBy('product_variant_id');
        $result = [];
        foreach ($rules as $rule) {
            $promotion = $rule->promotion;
            if ($rule->buyProduct?->status !== 'active' || $rule->buyProduct->gift_only
                || $rule->giftProduct?->status !== 'active'
                || (! $rule->giftProduct->can_be_gift && $rule->gift_product_id !== $rule->buy_product_id)
                || $rule->giftVariant?->status !== 'active' || ! $rule->giftVariant->track_inventory
                || ! in_array($rule->buy_product_id, $eligibleProductIds, true)
                || ($rule->buy_variant_id !== null && ! in_array($rule->buy_variant_id, $eligibleVariantIds, true))
                || ($channel === 'dealer' && $promotion->dealerTiers->isNotEmpty()
                    && ! $promotion->dealerTiers->contains('id', $effectiveTierId))
                || ($promotion->total_usage_limit !== null
                    && $promotion->redeemed_count >= $promotion->total_usage_limit)) {
                continue;
            }
            $balance = $balances->get($rule->gift_variant_id);
            $available = $balance === null ? '0.000'
                : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            $result[$rule->buy_product_id][] = [
                'code' => $promotion->code, 'name' => $promotion->name,
                'buy_variant_id' => $rule->buy_variant_id,
                'buy_variant_name' => $rule->buyVariant?->variant_name,
                'minimum_buy_quantity' => $rule->minimum_buy_quantity,
                'minimum_order_amount' => $promotion->minimum_order_amount,
                'gift_product_name' => $rule->giftProduct->name,
                'gift_image_url' => $rule->giftProduct->images
                    ->firstWhere('product_variant_id', $rule->gift_variant_id)?->url
                    ?? $rule->giftProduct->images->firstWhere('product_variant_id', null)?->url
                    ?? $rule->giftProduct->images->first()?->url,
                'gift_variant_name' => $rule->giftVariant->variant_name,
                'gift_sku' => $rule->giftVariant->sku,
                'gift_quantity' => $rule->gift_quantity,
                'repeat_per_multiple' => $rule->repeat_per_multiple,
                'dealer_tiers' => $channel === 'dealer' ? $promotion->dealerTiers->pluck('name')->all() : [],
                'gift_available' => bccomp($available, $rule->buy_variant_id === $rule->gift_variant_id
                    ? bcadd($rule->minimum_buy_quantity, $rule->gift_quantity, 3) : $rule->gift_quantity, 3) >= 0,
                'starts_at' => $promotion->starts_at,
                'ends_at' => $promotion->ends_at,
            ];
        }

        return $result;
    }
}
