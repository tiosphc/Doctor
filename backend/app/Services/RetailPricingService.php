<?php

namespace App\Services;

use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Support\Sku;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class RetailPricingService
{
    /** @return array{unit_price: string, currency: string, pricing_context: string, price_list_id: int, price_list_item_id: int} */
    public function resolveSku(string $sku, string $currency = 'VND', ?CarbonInterface $at = null, string $quantity = '1'): array
    {
        $variant = ProductVariant::query()->with('product')->where('sku', Sku::normalize($sku))->first();
        if ($variant === null) {
            $this->fail('PRICE_NOT_FOUND');
        }

        return $this->resolve($variant, $currency, $at, $quantity);
    }

    /** @return array{unit_price: string, currency: string, pricing_context: string, price_list_id: int, price_list_item_id: int} */
    public function resolve(ProductVariant $variant, string $currency = 'VND', ?CarbonInterface $at = null, string $quantity = '1'): array
    {
        $wholeQuantity = preg_replace('/\.0{1,3}$/', '', $quantity);
        if (preg_match('/^[1-9][0-9]{0,14}$/', $wholeQuantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive whole number.']);
        }
        if ($variant->status !== 'active' || $variant->product->status !== 'active'
            || $variant->product->gift_only || ! $variant->sellable_retail) {
            $this->fail('PRICE_NOT_FOUND');
        }
        $date = ($at ?? now())->toDateTimeString();
        $rows = ($variant->relationLoaded('priceItems') ? $variant->priceItems : PriceListItem::query()
            ->with('priceList')
            ->where('product_variant_id', $variant->id)
            ->where('status', 'active')
            ->where('minimum_quantity', 1)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->whereHas('priceList', fn ($query) => $query
                ->where('pricing_context', 'retail')
                ->where('scope_type', 'all')
                ->where('currency', $currency)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date)))
            ->get())
            ->filter(fn (PriceListItem $item): bool => $item->priceList->pricing_context === 'retail'
                && $item->status === 'active'
                && $item->priceList->scope_type === 'all'
                && $item->priceList->currency === $currency
                && $item->priceList->status === 'active'
                && bccomp($item->minimum_quantity, '1', 3) === 0
                && ($item->effective_from === null || $item->effective_from->lte($date))
                && ($item->effective_to === null || $item->effective_to->gte($date))
                && ($item->priceList->effective_from === null || $item->priceList->effective_from->lte($date))
                && ($item->priceList->effective_to === null || $item->priceList->effective_to->gte($date)))
            ->sort(fn (PriceListItem $left, PriceListItem $right): int => $right->priceList->priority <=> $left->priceList->priority
                ?: bccomp($right->minimum_quantity, $left->minimum_quantity, 3))
            ->values();
        if ($rows->isEmpty()) {
            $this->fail('PRICE_NOT_FOUND');
        }
        if ($rows->count() > 1 && $rows[0]->priceList->priority === $rows[1]->priceList->priority
            && bccomp($rows[0]->minimum_quantity, $rows[1]->minimum_quantity, 3) === 0) {
            $this->fail('PRICE_AMBIGUOUS');
        }
        $item = $rows[0];

        return [
            'unit_price' => $item->unit_price,
            'currency' => $item->priceList->currency,
            'pricing_context' => 'retail',
            'price_list_id' => $item->price_list_id,
            'price_list_item_id' => $item->id,
        ];
    }

    public function assertNoOverlap(PriceListItem $candidate): void
    {
        $list = $candidate->priceList;
        $this->assertValidRange($list->effective_from, $list->effective_to);
        $this->assertValidRange($candidate->effective_from, $candidate->effective_to);
        if ($list->status !== 'active' || $candidate->status !== 'active') {
            return;
        }
        $peers = PriceListItem::query()->with('priceList')
            ->where('product_variant_id', $candidate->product_variant_id)
            ->where('id', '!=', $candidate->id)
            ->get();
        foreach ($peers as $peer) {
            if ($peer->status !== 'active'
                || $peer->priceList->pricing_context !== 'retail'
                || $peer->priceList->scope_type !== 'all'
                || $peer->priceList->currency !== $list->currency
                || $peer->priceList->priority !== $list->priority
                || $peer->priceList->status !== 'active'
                || bccomp($peer->minimum_quantity, $candidate->minimum_quantity, 3) !== 0) {
                continue;
            }
            if ($this->rangesOverlap([$list->effective_from, $candidate->effective_from], [$list->effective_to, $candidate->effective_to], [$peer->priceList->effective_from, $peer->effective_from], [$peer->priceList->effective_to, $peer->effective_to])) {
                $this->fail('PRICE_AMBIGUOUS');
            }
        }
    }

    public function assertValidRange(?CarbonInterface $from, ?CarbonInterface $to): void
    {
        if ($from !== null && $to !== null && $to->lt($from)) {
            throw ValidationException::withMessages(['effective_to' => 'End time must be on or after start time.']);
        }
    }

    /** @param array<CarbonInterface|null> $startsA @param array<CarbonInterface|null> $endsA @param array<CarbonInterface|null> $startsB @param array<CarbonInterface|null> $endsB */
    private function rangesOverlap(array $startsA, array $endsA, array $startsB, array $endsB): bool
    {
        $startA = $this->latest($startsA);
        $endA = $this->earliest($endsA);
        $startB = $this->latest($startsB);
        $endB = $this->earliest($endsB);

        return ($endA === null || $startB === null || $startB <= $endA)
            && ($endB === null || $startA === null || $startA <= $endB);
    }

    /** @param array<CarbonInterface|null> $values */
    private function latest(array $values): ?CarbonInterface
    {
        $values = array_values(array_filter($values));

        return $values === [] ? null : Carbon::parse(max($values));
    }

    /** @param array<CarbonInterface|null> $values */
    private function earliest(array $values): ?CarbonInterface
    {
        $values = array_values(array_filter($values));

        return $values === [] ? null : Carbon::parse(min($values));
    }

    private function fail(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
