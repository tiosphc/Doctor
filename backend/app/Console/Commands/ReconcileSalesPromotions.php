<?php

namespace App\Console\Commands;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesPromotion;
use App\Models\SalesPromotionRedemption;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sales-promotions:reconcile {--dry-run} {--apply}')]
#[Description('Audit sales promotion usage, order snapshots and item discount allocations')]
class ReconcileSalesPromotions extends Command
{
    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply.');

            return self::FAILURE;
        }
        $anomalies = 0;
        SalesOrder::query()->whereNotNull('sales_promotion_id')->with(['items', 'promotionRedemption'])
            ->orderBy('id')->chunkById(200, function ($orders) use (&$anomalies): void {
                foreach ($orders as $order) {
                    $redemption = $order->promotionRedemption;
                    $discount = '0.00';
                    $base = '0.00';
                    $net = '0.00';
                    foreach ($order->items as $item) {
                        $base = bcadd($base, $item->base_amount, 2);
                        $discount = bcadd($discount, $item->discount_amount, 2);
                        $net = bcadd($net, $item->line_total, 2);
                        if (bccomp($item->discount_amount, '0', 2) < 0
                            || bccomp($item->discount_amount, $item->base_amount, 2) > 0
                            || bccomp($item->line_total, bcadd(bcsub($item->base_amount, $item->discount_amount, 2), $item->tax_amount, 2), 2) !== 0) {
                            $anomalies++;
                            $this->warn("Order {$order->order_code}: invalid item allocation {$item->id}.");
                        }
                    }
                    if ($redemption === null || $redemption->sales_promotion_id !== $order->sales_promotion_id
                        || $redemption->promotion_code_snapshot !== $order->promotion_code_snapshot
                        || $redemption->sales_channel !== $order->sales_channel
                        || $redemption->buyer_user_id !== $order->buyer_user_id
                        || $redemption->dealer_account_id !== $order->dealer_account_id
                        || bccomp($base, $order->subtotal, 2) !== 0
                        || bccomp($discount, $order->discount_total, 2) !== 0
                        || bccomp($net, $order->grand_total, 2) !== 0
                        || ($redemption !== null && bccomp($redemption->discount_amount, $discount, 2) !== 0)) {
                        $anomalies++;
                        $this->warn("Order {$order->order_code}: promotion snapshot mismatch.");
                    }
                    if ($redemption !== null && (($order->order_status === 'cancelled') !== ($redemption->status === 'released'))) {
                        $anomalies++;
                        $this->warn("Order {$order->order_code}: redemption lifecycle mismatch.");
                    }
                    if ($order->promotion_discount_type_snapshot === 'buy_a_get_b') {
                        $snapshot = $order->promotion_gift_snapshot;
                        $gifts = $order->items->where('is_gift', true);
                        $gift = $gifts->first();
                        if (! is_array($snapshot) || $gifts->count() !== 1 || $gift === null
                            || $gift->source_promotion_id !== $order->sales_promotion_id
                            || $gift->product_variant_id !== ($snapshot['gift_variant_id'] ?? null)
                            || bccomp($gift->quantity, (string) ($snapshot['actual_gift_quantity'] ?? '0'), 3) !== 0
                            || bccomp($gift->unit_price_snapshot, '0', 2) !== 0
                            || bccomp($gift->line_total, '0', 2) !== 0
                            || bccomp($order->discount_total, '0', 2) !== 0) {
                            $anomalies++;
                            $this->warn("Order {$order->order_code}: Gift promotion snapshot mismatch.");
                        }
                    } elseif ($order->items->contains('is_gift', true)) {
                        $anomalies++;
                        $this->warn("Order {$order->order_code}: Gift line has no Gift promotion snapshot.");
                    }
                }
            });
        SalesOrderItem::query()->where('is_gift', true)
            ->whereHas('order', fn ($orders) => $orders->whereNull('sales_promotion_id'))
            ->orderBy('id')->chunkById(200, function ($items) use (&$anomalies): void {
                foreach ($items as $item) {
                    $anomalies++;
                    $this->warn("Gift item {$item->id}: Order has no linked promotion.");
                }
            });
        SalesPromotionRedemption::query()->where(function ($query): void {
            $query->whereDoesntHave('salesOrder')
                ->orWhereDoesntHave('promotion')
                ->orWhereHas('salesOrder', fn ($orders) => $orders->whereNull('sales_promotion_id'));
        })
            ->orderBy('id')->chunkById(200, function ($redemptions) use (&$anomalies): void {
                foreach ($redemptions as $redemption) {
                    $anomalies++;
                    $this->warn("Redemption {$redemption->id}: missing or inconsistent linked order or promotion.");
                }
            });
        foreach (SalesPromotion::query()->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')])->cursor() as $promotion) {
            if ($promotion->total_usage_limit !== null && $promotion->redeemed_count > $promotion->total_usage_limit) {
                $anomalies++;
                $this->warn("Promotion {$promotion->code}: total usage exceeded.");
            }
            if ($promotion->per_buyer_usage_limit !== null) {
                foreach (['retail' => 'buyer_user_id', 'dealer' => 'dealer_account_id'] as $channel => $identityColumn) {
                    $identities = $promotion->redemptions()->where('status', 'redeemed')
                        ->where('sales_channel', $channel)
                        ->selectRaw("{$identityColumn}, COUNT(*) as usage_count")
                        ->groupBy($identityColumn)->get();
                    foreach ($identities as $identity) {
                        if ($identity->usage_count > $promotion->per_buyer_usage_limit) {
                            $anomalies++;
                            $this->warn("Promotion {$promotion->code}: {$channel} usage exceeded.");
                        }
                    }
                }
            }
        }
        $this->line("anomalies: {$anomalies}");
        $this->info('Financial records and usage are never rewritten by reconciliation.');

        return $anomalies === 0 ? self::SUCCESS : self::FAILURE;
    }
}
