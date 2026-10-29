<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesPromotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesPromotionService
{
    public function __construct(
        private readonly PromotionDiscountAllocator $allocator,
        private readonly AuditLogger $audit,
    ) {}

    public function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, SalesPromotion>
     */
    public function discountOccupancy(array $productIds, string $channel, ?int $effectiveTierId = null,
        ?int $excludePromotionId = null, ?string $startsAt = null, ?string $endsAt = null,
        bool $effectiveNow = false): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = Product::query()->whereKey($productIds)->get(['id', 'product_category_id']);
        $categoryIds = $products->pluck('product_category_id')->unique()->all();
        $query = SalesPromotion::query()->with(['targets', 'dealerTiers'])
            ->withCount(['redemptions as redeemed_count' => fn (Builder $query) => $query->where('status', 'redeemed')])
            ->forChannel($channel)
            ->whereIn('discount_type', ['percentage', 'fixed_amount'])
            ->when($excludePromotionId !== null, fn (Builder $query) => $query->whereKeyNot($excludePromotionId))
            ->where(fn (Builder $query) => $query->whereDoesntHave('targets')
                ->orWhereHas('targets', fn (Builder $targets) => $targets
                    ->whereIn('product_id', $productIds)
                    ->orWhereIn('product_category_id', $categoryIds)));

        if ($effectiveNow) {
            $query->effectiveAt();
        } else {
            $query->where('status', 'active')
                ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
            if ($endsAt !== null) {
                $query->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $endsAt));
            }
            if ($startsAt !== null) {
                $query->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $startsAt));
            }
        }

        $occupancy = [];
        foreach ($query->orderBy('id')->get() as $promotion) {
            if ($effectiveNow && $promotion->total_usage_limit !== null
                && $promotion->redeemed_count >= $promotion->total_usage_limit) {
                continue;
            }
            if ($channel === 'dealer' && $effectiveTierId !== null
                && $promotion->dealerTiers->isNotEmpty()
                && ! $promotion->dealerTiers->contains('id', $effectiveTierId)) {
                continue;
            }
            foreach ($products as $product) {
                if (! isset($occupancy[$product->id])
                    && $this->targetsProduct($promotion, $product->id, $product->product_category_id)) {
                    $occupancy[$product->id] = $promotion;
                }
            }
        }

        return $occupancy;
    }

    public function discountedUnitPrice(string $unitPrice, SalesPromotion $promotion): string
    {
        $discount = $promotion->discount_type === 'fixed_amount'
            ? $promotion->discount_value
            : bcadd(bcdiv(bcmul($unitPrice, $promotion->discount_value, 4), '100', 4), '0.005', 2);
        if ($promotion->max_discount_amount !== null
            && bccomp($discount, $promotion->max_discount_amount, 2) > 0) {
            $discount = $promotion->max_discount_amount;
        }
        if (bccomp($discount, $unitPrice, 2) > 0) {
            $discount = $unitPrice;
        }

        return bcsub($unitPrice, $discount, 2);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, SalesPromotion>
     */
    public function retailDiscountOccupancy(array $productIds, ?int $excludePromotionId = null,
        ?string $startsAt = null, ?string $endsAt = null, bool $effectiveNow = false): array
    {
        return $this->discountOccupancy($productIds, 'retail', null, $excludePromotionId,
            $startsAt, $endsAt, $effectiveNow);
    }

    /** @param array<string, mixed> $data */
    public function assertDiscountAvailability(array $data, ?int $excludePromotionId = null): void
    {
        if ($data['discount_type'] === 'buy_a_get_b' || $data['status'] !== 'active') {
            return;
        }
        $products = Product::query()->where('status', 'active');
        if (($data['product_ids'] ?? []) !== [] || ($data['category_ids'] ?? []) !== []) {
            $products->where(fn (Builder $query) => $query
                ->whereIn('id', $data['product_ids'] ?? [])
                ->orWhereIn('product_category_id', $data['category_ids'] ?? []));
        }
        $selected = $products->orderBy('id')->lockForUpdate()->get(['id', 'name', 'product_category_id']);
        $channels = $data['sales_scope'] === 'both' ? ['retail', 'dealer'] : [$data['sales_scope']];
        foreach ($channels as $channel) {
            $candidates = SalesPromotion::query()->with(['targets', 'dealerTiers'])
                ->forChannel($channel)->where('status', 'active')
                ->whereIn('discount_type', ['percentage', 'fixed_amount'])
                ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->when($excludePromotionId !== null, fn (Builder $query) => $query->whereKeyNot($excludePromotionId))
                ->when(isset($data['ends_at']), fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->whereNull('starts_at')
                        ->orWhere('starts_at', '<=', $data['ends_at'])))
                ->when(isset($data['starts_at']), fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>=', $data['starts_at'])))
                ->orderBy('id')->get();
            foreach ($candidates as $existing) {
                if ($channel === 'dealer' && ($data['dealer_tier_ids'] ?? []) !== []
                    && $existing->dealerTiers->isNotEmpty()
                    && $existing->dealerTiers->pluck('id')->intersect($data['dealer_tier_ids'])->isEmpty()) {
                    continue;
                }
                foreach ($selected as $product) {
                    if (! $this->targetsProduct($existing, $product->id, $product->product_category_id)) {
                        continue;
                    }
                    throw new HttpResponseException(response()->json([
                        'code' => 'PRODUCT_ALREADY_HAS_ACTIVE_PROMOTION',
                        'message' => 'Sản phẩm đã có ưu đãi giảm giá đang hoạt động.',
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'existing_promotion_id' => $existing->id,
                        'existing_promotion_name' => $existing->name,
                        'discount_value' => $existing->discount_value,
                        'sales_channel' => $channel,
                    ], 409));
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function assertRetailDiscountAvailability(array $data, ?int $excludePromotionId = null): void
    {
        $this->assertDiscountAvailability($data, $excludePromotionId);
    }

    private function targetsProduct(SalesPromotion $promotion, int $productId, int $categoryId): bool
    {
        return $promotion->targets->isEmpty() || $promotion->targets->contains(
            fn ($target): bool => $target->product_id === $productId || $target->product_category_id === $categoryId
        );
    }

    /** @param list<array{product_variant_id: int, product_id: int, amount: string, quantity?: string}> $lines
     * @return array<string, mixed>
     */
    public function quote(string $code, string $channel, int $buyerId, ?int $dealerAccountId, string $subtotal, array $lines, bool $lock = false, ?int $warehouseId = null, ?int $effectiveTierId = null): array
    {
        $normalized = $this->normalize($code);
        if ($normalized === '') {
            $this->conflict('PROMOTION_NOT_FOUND');
        }
        $query = SalesPromotion::query()->with(['targets', 'giftRule', 'dealerTiers'])->where('normalized_code', $normalized);
        $promotion = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($promotion === null) {
            $this->conflict('PROMOTION_NOT_FOUND');
        }
        if ($promotion->status !== 'active') {
            $this->conflict('PROMOTION_INACTIVE');
        }
        if ($promotion->starts_at !== null && $promotion->starts_at->isFuture()) {
            $this->conflict('PROMOTION_NOT_STARTED');
        }
        if ($promotion->ends_at !== null && $promotion->ends_at->isPast()) {
            $this->conflict('PROMOTION_EXPIRED');
        }
        if ($promotion->sales_scope !== 'both' && $promotion->sales_scope !== $channel) {
            $this->conflict('PROMOTION_NOT_APPLICABLE_TO_CHANNEL');
        }
        if ($channel === 'dealer' && $promotion->dealerTiers->isNotEmpty()
            && ! $promotion->dealerTiers->contains('id', $effectiveTierId)) {
            $this->conflict('PROMOTION_NOT_APPLICABLE_TO_TIER');
        }
        if (bccomp($subtotal, $promotion->minimum_order_amount, 2) < 0) {
            $this->conflict('PROMOTION_MINIMUM_NOT_MET');
        }
        if ($promotion->total_usage_limit !== null && $promotion->redemptions()->where('status', 'redeemed')
            ->lockForUpdate()->get(['id'])->count() >= $promotion->total_usage_limit) {
            $this->conflict('PROMOTION_USAGE_LIMIT_REACHED');
        }
        if ($promotion->per_buyer_usage_limit !== null) {
            $identity = $channel === 'retail' ? ['buyer_user_id', $buyerId] : ['dealer_account_id', $dealerAccountId];
            if ($identity[1] === null || $promotion->redemptions()->where('status', 'redeemed')
                ->where($identity[0], $identity[1])->lockForUpdate()->get(['id'])->count() >= $promotion->per_buyer_usage_limit) {
                $this->conflict('PROMOTION_BUYER_LIMIT_REACHED');
            }
        }
        if ($promotion->discount_type === 'buy_a_get_b') {
            return $this->quoteGift($promotion, $channel, $subtotal, $lines, $warehouseId, $effectiveTierId, $lock);
        }
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))
            ->pluck('product_category_id', 'id');
        $productTargets = $promotion->targets->pluck('product_id')->filter()->all();
        $categoryTargets = $promotion->targets->pluck('product_category_id')->filter()->all();
        $eligible = [];
        $fixedEligible = [];
        $eligibleSubtotal = '0.00';
        foreach ($lines as $line) {
            if ($promotion->targets->isNotEmpty()
                && ! in_array($line['product_id'], $productTargets, true)
                && ! in_array($products[$line['product_id']] ?? null, $categoryTargets, true)) {
                continue;
            }
            $eligible[] = ['product_variant_id' => $line['product_variant_id'], 'amount' => $line['amount']];
            if ($promotion->discount_type === 'fixed_amount') {
                $lineDiscount = bcadd(bcmul($promotion->discount_value, $line['quantity'] ?? '1', 3), '0.005', 2);
                if (bccomp($lineDiscount, $line['amount'], 2) > 0) {
                    $lineDiscount = $line['amount'];
                }
                $fixedEligible[] = ['product_variant_id' => $line['product_variant_id'], 'amount' => $lineDiscount];
            }
            $eligibleSubtotal = bcadd($eligibleSubtotal, $line['amount'], 2);
        }
        if (bccomp($eligibleSubtotal, '0', 2) <= 0) {
            $this->conflict('PROMOTION_NO_ELIGIBLE_ITEMS');
        }
        $discount = $promotion->discount_type === 'percentage'
            ? bcadd(bcdiv(bcmul($eligibleSubtotal, $promotion->discount_value, 4), '100', 4), '0.005', 2)
            : array_reduce($fixedEligible, fn (string $total, array $line): string => bcadd($total, $line['amount'], 2), '0.00');
        if ($promotion->max_discount_amount !== null && bccomp($discount, $promotion->max_discount_amount, 2) > 0) {
            $discount = $promotion->max_discount_amount;
        }
        if (bccomp($discount, $eligibleSubtotal, 2) > 0) {
            $discount = $eligibleSubtotal;
        }
        if (bccomp($discount, '0', 2) <= 0) {
            $this->conflict('PROMOTION_DISCOUNT_TOO_SMALL');
        }
        $allocations = $this->allocator->allocate($promotion->discount_type === 'fixed_amount' ? $fixedEligible : $eligible, $discount);
        $ruleState = [$promotion->id, $promotion->updated_at?->toJSON(), $promotion->code,
            $promotion->name, $promotion->discount_type, $promotion->discount_value,
            $promotion->max_discount_amount, $promotion->minimum_order_amount, $promotion->sales_scope,
            $promotion->starts_at?->toJSON(), $promotion->ends_at?->toJSON(),
            $promotion->total_usage_limit, $promotion->per_buyer_usage_limit, $promotion->status,
            $promotion->targets->map(fn ($target): array => [$target->product_id, $target->product_category_id])->sort()->values()->all(),
            $promotion->dealerTiers->pluck('id')->sort()->values()->all()];

        return ['promotion_id' => $promotion->id, 'code' => $promotion->code, 'name' => $promotion->name,
            'discount_type' => $promotion->discount_type, 'discount_value' => $promotion->discount_value,
            'eligible_subtotal' => $eligibleSubtotal, 'discount_amount' => $discount,
            'subtotal_before_discount' => $subtotal, 'grand_total_after_discount' => bcsub($subtotal, $discount, 2),
            'allocations' => $allocations, 'fingerprint' => hash('sha256', json_encode($ruleState, JSON_THROW_ON_ERROR))];
    }

    /**
     * @param  list<array{product_variant_id: int, product_id: int, amount: string, quantity?: string}>  $lines
     * @return array{discount: ?array, discounts: array, gift: ?array}
     */
    public function bestQuotes(string $channel, int $buyerId, ?int $dealerAccountId, string $subtotal,
        array $lines, ?int $warehouseId = null, ?int $effectiveTierId = null,
        ?string &$giftUnavailableReason = null): array
    {
        if ($lines === [] || bccomp($subtotal, '0', 2) <= 0) {
            return ['discount' => null, 'discounts' => [], 'gift' => null];
        }
        $candidates = SalesPromotion::query()->effectiveAt()->forChannel($channel)
            ->whereIn('discount_type', ['percentage', 'fixed_amount', 'buy_a_get_b'])
            ->orderBy('id')->pluck('code');
        $bestDiscount = null;
        $discounts = [];
        $discountedVariants = [];
        $bestGift = null;
        foreach ($candidates as $code) {
            try {
                $quote = $this->quote($code, $channel, $buyerId, $dealerAccountId,
                    $subtotal, $lines, false, $warehouseId, $effectiveTierId);
                if ($quote['discount_type'] === 'buy_a_get_b') {
                    if ($bestGift === null || ($quote['qualified'] && ! $bestGift['qualified'])) {
                        $bestGift = $quote;
                    }

                    continue;
                }
                if (array_intersect(array_keys($quote['allocations']), $discountedVariants) === []) {
                    $discounts[] = $quote;
                    $discountedVariants = array_merge($discountedVariants, array_keys($quote['allocations']));
                    if ($bestDiscount === null
                        || bccomp($quote['discount_amount'], $bestDiscount['discount_amount'], 2) > 0) {
                        $bestDiscount = $quote;
                    }
                }
            } catch (HttpResponseException $exception) {
                if (($exception->getResponse()->getData(true)['code'] ?? null) === 'PROMOTION_GIFT_OUT_OF_STOCK') {
                    $giftUnavailableReason = 'PROMOTION_GIFT_OUT_OF_STOCK';
                }

                continue;
            }
        }

        return ['discount' => $bestDiscount, 'discounts' => $discounts, 'gift' => $bestGift];
    }

    public function redeem(SalesOrder $order, string $code): void
    {
        $lines = $order->items()->where('is_gift', false)->orderBy('product_variant_id')->get()->map(fn ($item): array => [
            'product_variant_id' => $item->product_variant_id, 'product_id' => $item->product_id,
            'amount' => $item->base_amount, 'quantity' => $item->quantity])->all();
        $quote = $this->quote($code, $order->sales_channel, $order->buyer_user_id,
            $order->dealer_account_id, $order->subtotal, $lines, true, $order->warehouse_id, $order->effective_tier_id_snapshot);
        if ($order->sales_voucher_id !== null
            || $order->promotionRedemptions()->where('sales_promotion_id', $quote['promotion_id'])->exists()
            || ($order->sales_promotion_id !== null
                && $order->promotion_gift_snapshot !== null)) {
            $this->conflict('PROMOTION_STACKING_NOT_SUPPORTED');
        }
        if ($quote['discount_type'] === 'buy_a_get_b' && ! $quote['qualified']) {
            return;
        }
        if ($order->sales_promotion_id === null) {
            foreach ($order->items()->where('is_gift', false)->get() as $item) {
                $discount = $quote['allocations'][$item->product_variant_id] ?? '0.00';
                $item->update(['discount_amount' => $discount,
                    'line_total' => bcsub($item->base_amount, $discount, 2)]);
            }
            $order->update(['sales_promotion_id' => $quote['promotion_id'],
                'promotion_code_snapshot' => $quote['code'], 'promotion_name_snapshot' => $quote['name'],
                'promotion_discount_type_snapshot' => $quote['discount_type'],
                'promotion_discount_value_snapshot' => $quote['discount_value'],
                'discount_total' => $quote['discount_amount'], 'grand_total' => $quote['grand_total_after_discount'],
                'promotion_gift_snapshot' => isset($quote['gift_snapshot'])
                    ? ['promotion_id' => $quote['promotion_id'], ...$quote['gift_snapshot']] : null]);
        } elseif ($quote['discount_type'] === 'buy_a_get_b') {
            $order->update(['promotion_gift_snapshot' => [
                'promotion_id' => $quote['promotion_id'], ...$quote['gift_snapshot'],
            ]]);
        } else {
            foreach ($order->items()->where('is_gift', false)->get() as $item) {
                $discount = $quote['allocations'][$item->product_variant_id] ?? null;
                if ($discount === null) {
                    continue;
                }
                if (bccomp($item->discount_amount, '0', 2) > 0) {
                    $this->conflict('PRODUCT_ALREADY_HAS_ACTIVE_PROMOTION');
                }
                $item->update(['discount_amount' => $discount,
                    'line_total' => bcsub($item->line_total, $discount, 2)]);
            }
            $order->update(['discount_total' => bcadd($order->discount_total, $quote['discount_amount'], 2),
                'grand_total' => bcsub($order->grand_total, $quote['discount_amount'], 2)]);
        }
        if ($quote['discount_type'] === 'buy_a_get_b') {
            $this->createGiftItem($order, $quote);
        }
        $redemption = $order->promotionRedemptions()->create([
            'sales_promotion_id' => $quote['promotion_id'], 'sales_channel' => $order->sales_channel,
            'buyer_user_id' => $order->buyer_user_id,
            'dealer_account_id' => $order->dealer_account_id,
            'promotion_code_snapshot' => $quote['code'], 'discount_type_snapshot' => $quote['discount_type'],
            'discount_value_snapshot' => $quote['discount_value'], 'discount_amount' => $quote['discount_amount'],
            'status' => 'redeemed', 'redeemed_at' => now(),
        ]);
        $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_SALES_PROMOTION,
            $redemption, 'Sales promotion redeemed', metadata: [
                'sales_order_id' => $order->id, 'sales_order_code' => $order->order_code,
                'promotion_id' => $quote['promotion_id'], 'promotion_code' => $quote['code'],
                'actor_id' => $order->buyer_user_id,
            ]);
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private function quoteGift(SalesPromotion $promotion, string $channel, string $subtotal, array $lines, ?int $warehouseId, ?int $effectiveTierId, bool $lock): array
    {
        $rule = $promotion->giftRule;
        if ($rule === null) {
            $this->conflict('PROMOTION_GIFT_RULE_INVALID');
        }
        $buyProduct = Product::query()->find($rule->buy_product_id);
        $giftProduct = Product::query()->find($rule->gift_product_id);
        $giftVariant = ProductVariant::query()->with(['product.images', 'unit'])->find($rule->gift_variant_id);
        if ($buyProduct?->status !== 'active' || $buyProduct->gift_only
            || $giftProduct?->status !== 'active'
            || (! $giftProduct->can_be_gift && $rule->gift_product_id !== $rule->buy_product_id)
            || $giftVariant?->status !== 'active' || ! $giftVariant->track_inventory
            || $giftVariant->product_id !== $rule->gift_product_id) {
            $this->conflict('PROMOTION_GIFT_RULE_INVALID');
        }
        if ($rule->buy_variant_id !== null) {
            $buyVariant = ProductVariant::query()->find($rule->buy_variant_id);
            if ($buyVariant?->product_id !== $rule->buy_product_id || $buyVariant->status !== 'active'
                || ! $buyVariant->track_inventory
                || ($channel === 'retail' && ! $buyVariant->sellable_retail)
                || ($channel === 'dealer' && ! $buyVariant->sellable_dealer)) {
                $this->conflict('PROMOTION_GIFT_RULE_INVALID');
            }
        }
        $paidQuantity = '0.000';
        foreach ($lines as $line) {
            if ((int) $line['product_id'] === $rule->buy_product_id
                && ($rule->buy_variant_id === null || (int) $line['product_variant_id'] === $rule->buy_variant_id)) {
                $paidQuantity = bcadd($paidQuantity, (string) ($line['quantity'] ?? '0'), 3);
            }
        }
        $multiplier = bccomp($paidQuantity, $rule->minimum_buy_quantity, 3) < 0 ? '0'
            : ($rule->repeat_per_multiple ? bcdiv($paidQuantity, $rule->minimum_buy_quantity, 0) : '1');
        $giftQuantity = bcmul($multiplier, $rule->gift_quantity, 3);
        $qualified = bccomp($giftQuantity, '0', 3) > 0;
        $available = null;
        if ($qualified && $warehouseId !== null) {
            $balanceQuery = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $giftVariant->id);
            $balance = ($lock ? $balanceQuery->lockForUpdate() : $balanceQuery)->first();
            $available = $balance === null ? '0.000' : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            $paidGiftSkuQuantity = '0.000';
            foreach ($lines as $line) {
                if ((int) $line['product_variant_id'] === $giftVariant->id) {
                    $paidGiftSkuQuantity = bcadd($paidGiftSkuQuantity, (string) ($line['quantity'] ?? '0'), 3);
                }
            }
            if (bccomp($available, bcadd($giftQuantity, $paidGiftSkuQuantity, 3), 3) < 0) {
                $this->conflict('PROMOTION_GIFT_OUT_OF_STOCK');
            }
        }
        $snapshot = ['buy_product_id' => $rule->buy_product_id, 'buy_variant_id' => $rule->buy_variant_id,
            'minimum_buy_quantity' => $rule->minimum_buy_quantity, 'gift_product_id' => $rule->gift_product_id,
            'gift_variant_id' => $rule->gift_variant_id, 'gift_quantity' => $rule->gift_quantity,
            'repeat_per_multiple' => $rule->repeat_per_multiple, 'actual_gift_quantity' => $giftQuantity];
        $remaining = $qualified ? '0.000' : bcsub($rule->minimum_buy_quantity, $paidQuantity, 3);
        $fingerprint = hash('sha256', json_encode([$promotion->id, $promotion->updated_at?->toJSON(),
            $promotion->status, $promotion->starts_at?->toJSON(), $promotion->ends_at?->toJSON(),
            $promotion->total_usage_limit, $promotion->per_buyer_usage_limit, $rule->updated_at?->toJSON(),
            $snapshot, $promotion->dealerTiers->pluck('id')->sort()->values()->all(), $available], JSON_THROW_ON_ERROR));

        return ['promotion_id' => $promotion->id, 'code' => $promotion->code, 'name' => $promotion->name,
            'discount_type' => 'buy_a_get_b', 'discount_value' => '0.00', 'discount_amount' => '0.00',
            'grand_total_after_discount' => $subtotal, 'allocations' => [], 'fingerprint' => $fingerprint,
            'qualified' => $qualified, 'remaining_buy_quantity' => $remaining,
            'gift_quantity' => $giftQuantity, 'gift_variant_id' => $giftVariant->id,
            'gift_sku' => $giftVariant->sku, 'gift_product_name' => $giftProduct->name,
            'gift_variant_name' => $giftVariant->variant_name, 'gift_available_quantity' => $available,
            'gift_snapshot' => $snapshot];
    }

    /** @param array<string, mixed> $quote */
    private function createGiftItem(SalesOrder $order, array $quote): void
    {
        $variant = ProductVariant::query()->with(['product.images', 'unit'])->findOrFail($quote['gift_variant_id']);
        $images = $variant->product->images->sortBy('sort_order');
        $image = $images->firstWhere('product_variant_id', $variant->id)
            ?? $images->firstWhere('product_variant_id', null) ?? $images->first();
        $order->items()->create([
            'product_id' => $variant->product_id, 'product_variant_id' => $variant->id,
            'product_code_snapshot' => $variant->product->product_code,
            'product_name_snapshot' => $variant->product->name, 'image_path_snapshot' => $image?->path,
            'sku_snapshot' => $variant->sku, 'variant_name_snapshot' => $variant->variant_name,
            'unit_code_snapshot' => $variant->unit->code, 'unit_name_snapshot' => $variant->unit->name,
            'quantity' => $quote['gift_quantity'], 'pricing_context_snapshot' => $order->sales_channel,
            'price_resolution_fingerprint' => $quote['fingerprint'], 'unit_price_snapshot' => '0.00',
            'base_amount' => '0.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'line_total' => '0.00', 'is_gift' => true, 'source_promotion_id' => $quote['promotion_id'],
        ]);
    }

    public function release(SalesOrder $order, ?int $actorId = null): void
    {
        if (! $order->promotionRedemptions()->where('status', 'redeemed')->exists()) {
            return;
        }
        foreach ($order->promotionRedemptions()->where('status', 'redeemed')->orderBy('sales_promotion_id')->lockForUpdate()->get() as $redemption) {
            if ($redemption->sales_promotion_id !== null) {
                SalesPromotion::query()->whereKey($redemption->sales_promotion_id)->lockForUpdate()->firstOrFail();
            }
            $redemption->update(['status' => 'released', 'released_at' => now()]);
            $this->audit->log(AuditLogger::ACTION_RELEASE, AuditLogger::MODULE_SALES_PROMOTION,
                $redemption, 'Sales promotion usage released after cancellation', metadata: [
                    'sales_order_id' => $order->id, 'sales_order_code' => $order->order_code,
                    'promotion_id' => $redemption->sales_promotion_id,
                    'promotion_code' => $redemption->promotion_code_snapshot, 'actor_id' => $actorId,
                ]);
        }
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
