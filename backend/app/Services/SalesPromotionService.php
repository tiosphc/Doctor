<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesPromotion;
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
        $eligibleSubtotal = '0.00';
        foreach ($lines as $line) {
            if ($promotion->targets->isNotEmpty()
                && ! in_array($line['product_id'], $productTargets, true)
                && ! in_array($products[$line['product_id']] ?? null, $categoryTargets, true)) {
                continue;
            }
            $eligible[] = ['product_variant_id' => $line['product_variant_id'], 'amount' => $line['amount']];
            $eligibleSubtotal = bcadd($eligibleSubtotal, $line['amount'], 2);
        }
        if (bccomp($eligibleSubtotal, '0', 2) <= 0) {
            $this->conflict('PROMOTION_NO_ELIGIBLE_ITEMS');
        }
        $discount = $promotion->discount_type === 'percentage'
            ? bcadd(bcdiv(bcmul($eligibleSubtotal, $promotion->discount_value, 4), '100', 4), '0.005', 2)
            : $promotion->discount_value;
        if ($promotion->max_discount_amount !== null && bccomp($discount, $promotion->max_discount_amount, 2) > 0) {
            $discount = $promotion->max_discount_amount;
        }
        if (bccomp($discount, $eligibleSubtotal, 2) > 0) {
            $discount = $eligibleSubtotal;
        }
        if (bccomp($discount, '0', 2) <= 0) {
            $this->conflict('PROMOTION_DISCOUNT_TOO_SMALL');
        }
        $allocations = $this->allocator->allocate($eligible, $discount);
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
     * Qualified gifts take precedence over cash discounts; discounts remain the fallback.
     *
     * @param  list<array{product_variant_id: int, product_id: int, amount: string, quantity?: string}>  $lines
     * @return array<string, mixed>|null
     */
    public function bestQuote(string $channel, int $buyerId, ?int $dealerAccountId, string $subtotal,
        array $lines, ?int $warehouseId = null, ?int $effectiveTierId = null,
        ?string &$giftUnavailableReason = null): ?array
    {
        if ($lines === [] || bccomp($subtotal, '0', 2) <= 0) {
            return null;
        }
        $candidates = SalesPromotion::query()->where('status', 'active')
            ->whereIn('sales_scope', [$channel, 'both'])->orderBy('id')->pluck('code');
        $best = null;
        foreach ($candidates as $code) {
            try {
                $quote = $this->quote($code, $channel, $buyerId, $dealerAccountId,
                    $subtotal, $lines, false, $warehouseId, $effectiveTierId);
                if ($quote['discount_type'] === 'buy_a_get_b') {
                    if ($quote['qualified'] && ($best === null || $best['discount_type'] !== 'buy_a_get_b')) {
                        $best = $quote;
                    }

                    continue;
                }
                if ($best === null || ($best['discount_type'] !== 'buy_a_get_b'
                    && bccomp($quote['discount_amount'], $best['discount_amount'], 2) > 0)) {
                    $best = $quote;
                }
            } catch (HttpResponseException $exception) {
                if (($exception->getResponse()->getData(true)['code'] ?? null) === 'PROMOTION_GIFT_OUT_OF_STOCK') {
                    $giftUnavailableReason = 'PROMOTION_GIFT_OUT_OF_STOCK';
                }

                continue;
            }
        }

        return $best;
    }

    public function redeem(SalesOrder $order, string $code): void
    {
        if ($order->sales_promotion_id !== null || $order->sales_voucher_id !== null) {
            $this->conflict('PROMOTION_STACKING_NOT_SUPPORTED');
        }
        $lines = $order->items()->where('is_gift', false)->orderBy('product_variant_id')->get()->map(fn ($item): array => [
            'product_variant_id' => $item->product_variant_id, 'product_id' => $item->product_id,
            'amount' => $item->base_amount, 'quantity' => $item->quantity])->all();
        $quote = $this->quote($code, $order->sales_channel, $order->buyer_user_id,
            $order->dealer_account_id, $order->subtotal, $lines, true, $order->warehouse_id, $order->effective_tier_id_snapshot);
        if ($quote['discount_type'] === 'buy_a_get_b' && ! $quote['qualified']) {
            return;
        }
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
            'promotion_gift_snapshot' => $quote['gift_snapshot'] ?? null]);
        if ($quote['discount_type'] === 'buy_a_get_b') {
            $this->createGiftItem($order, $quote);
        }
        $redemption = $order->promotionRedemption()->create([
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
        if ($order->sales_promotion_id === null) {
            return;
        }
        SalesPromotion::query()->whereKey($order->sales_promotion_id)->lockForUpdate()->firstOrFail();
        $redemption = $order->promotionRedemption()->where('status', 'redeemed')->lockForUpdate()->first();
        if ($redemption === null) {
            return;
        }
        $redemption->update(['status' => 'released', 'released_at' => now()]);
        $this->audit->log(AuditLogger::ACTION_RELEASE, AuditLogger::MODULE_SALES_PROMOTION,
            $redemption, 'Sales promotion usage released after cancellation', metadata: [
                'sales_order_id' => $order->id, 'sales_order_code' => $order->order_code,
                'promotion_id' => $order->sales_promotion_id,
                'promotion_code' => $order->promotion_code_snapshot, 'actor_id' => $actorId,
            ]);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
