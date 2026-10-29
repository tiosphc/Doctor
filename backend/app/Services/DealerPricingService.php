<?php

namespace App\Services;

use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealerPricingService
{
    /** @return array<string, mixed> */
    public function resolve(ProductVariant $variant, DealerTier $tier, string $quantity, bool $enforceMoq = true): array
    {
        $quantity = preg_replace('/\.0{1,3}$/', '', $quantity);
        $this->assertQuantity($variant, $quantity);
        $price = $this->pricesFor(new Collection([$variant]), $tier)[$variant->id] ?? null;
        if ($price === null) {
            $this->fail('DEALER_PRICE_NOT_FOUND');
        }
        $meetsMoq = bccomp($quantity, $price['minimum_quantity'], 3) >= 0;
        if ($enforceMoq && ! $meetsMoq) {
            throw new HttpResponseException(response()->json([
                'code' => 'DEALER_MOQ_NOT_MET', 'message' => 'DEALER_MOQ_NOT_MET',
                'sku' => $variant->sku, 'requested' => $quantity, 'minimum_quantity' => $price['minimum_quantity'],
            ], 409));
        }

        return [...$price, 'quantity' => $quantity, 'meets_moq' => $meetsMoq,
            'line_total' => bcadd(bcmul($price['unit_price'], $quantity, 5), '0.005', 2)];
    }

    /** @param Collection<int, ProductVariant> $variants @return array<int, array<string, mixed>> */
    public function pricesFor(Collection $variants, DealerTier $tier): array
    {
        if ($tier->status !== 'active') {
            $this->fail('DEALER_TIER_INACTIVE');
        }
        $eligible = $variants->filter(fn (ProductVariant $variant): bool => $variant->status === 'active'
            && $variant->sellable_dealer && $variant->product->status === 'active' && ! $variant->product->gift_only);
        if ($eligible->isEmpty()) {
            return [];
        }
        $at = now()->toDateTimeString();
        $query = PriceListItem::query()->with(['priceList' => function ($query): void {
            if (DB::transactionLevel() > 0) {
                $query->lockForUpdate();
            }
        }])
            ->whereIn('product_variant_id', $eligible->pluck('id')->all())->where('status', 'active')
            ->where('unit_price', '>', 0)->where('minimum_quantity', '>', 0)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at));
        $eligibleLists = fn ($query) => $query->where('pricing_context', 'dealer')
            ->where('scope_type', 'tier')->where('dealer_tier_id', $tier->id)
            ->where('currency', 'VND')->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at));
        if (DB::transactionLevel() > 0) {
            $listIds = $eligibleLists(PriceList::query())->orderBy('id')->lockForUpdate()->pluck('id');
            $query->whereIn('price_list_id', $listIds);
            $query->lockForUpdate();
        } else {
            $query->whereHas('priceList', $eligibleLists);
        }
        $items = $query->get()->groupBy('product_variant_id');
        $result = [];
        foreach ($items as $variantId => $rows) {
            $ordered = $rows->sort(fn (PriceListItem $left, PriceListItem $right): int => $right->priceList->priority <=> $left->priceList->priority)->values();
            $selected = $ordered->first();
            if ($ordered->count() > 1 && $selected->priceList->priority === $ordered[1]->priceList->priority) {
                $this->fail('DEALER_PRICE_AMBIGUOUS');
            }
            $unitPrice = bcadd((string) $selected->unit_price, '0', 2);
            $result[(int) $variantId] = [
                'unit_price' => $unitPrice,
                'base_unit_price' => $unitPrice,
                'minimum_quantity' => $selected->minimum_quantity,
                'currency' => $selected->priceList->currency,
                'price_list_id' => $selected->price_list_id,
                'price_list_item_id' => $selected->id,
                'price_list_updated_at' => $selected->priceList->updated_at?->toIso8601String(),
                'price_item_updated_at' => $selected->updated_at?->toIso8601String(),
                'price_list_effective_from' => $selected->priceList->effective_from?->toIso8601String(),
                'price_list_effective_to' => $selected->priceList->effective_to?->toIso8601String(),
                'price_item_effective_from' => $selected->effective_from?->toIso8601String(),
                'price_item_effective_to' => $selected->effective_to?->toIso8601String(),
            ];
        }

        return $result;
    }

    private function assertQuantity(ProductVariant $variant, string $quantity): void
    {
        if (preg_match('/^[1-9][0-9]{0,14}$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive whole number.']);
        }
    }

    private function fail(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
