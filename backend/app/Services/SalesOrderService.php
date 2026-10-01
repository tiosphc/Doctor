<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Support\Sku;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        private readonly RetailPricingService $pricing,
        private readonly DealerPricingService $dealerPricing,
        private readonly DealerEffectivePricingService $dealerEffectivePricing,
        private readonly InventoryReservationService $inventory,
        private readonly AuditLogger $audit,
        private readonly SalesPromotionService $promotions,
        private readonly SalesVoucherService $salesVouchers,
        private readonly OrderPaymentSummaryService $paymentSummaries,
    ) {}

    /** @param array{warehouse_id: int, items: list<array{sku: string, quantity: int|string}>} $data
     * @return array{items: list<array<string, mixed>>, subtotal: string, discount_total: string, shipping_total: string, grand_total: string}
     */
    public function previewRetail(array $data): array
    {
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        if ($warehouse->status !== 'active') {
            $this->conflict('WAREHOUSE_INACTIVE');
        }

        $skus = array_map(static fn (array $item): string => Sku::normalize($item['sku']), $data['items']);
        if (count($skus) !== count(array_unique($skus))) {
            throw ValidationException::withMessages(['items' => 'Duplicate SKU lines are not allowed.']);
        }
        $variants = ProductVariant::query()->with(['product.images', 'unit'])
            ->whereIn('sku', $skus)->get()->keyBy('sku');
        $balances = DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->get()->keyBy('product_variant_id');

        $items = [];
        $subtotal = '0.00';
        foreach ($data['items'] as $index => $item) {
            $sku = $skus[$index];
            $variant = $variants->get($sku);
            if ($variant === null) {
                $this->conflict('SKU_UNAVAILABLE');
            }
            $quote = $this->quote($variant, (string) $item['quantity'], 'VND');
            $balance = $balances->get($variant->id);
            $available = $balance === null ? '0.000'
                : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            $available = bccomp($available, '0', 3) < 0 ? '0.000' : $available;
            $items[] = [
                'sku' => $sku, 'product_name' => $quote['product_name_snapshot'],
                'variant_name' => $quote['variant_name_snapshot'], 'unit_name' => $quote['unit_name_snapshot'],
                'quantity' => $quote['quantity'], 'unit_price' => $quote['unit_price_snapshot'],
                'line_total' => $quote['line_total'], 'available_quantity' => $available,
                'insufficient_stock' => bccomp($available, (string) $item['quantity'], 3) < 0,
            ];
            $subtotal = bcadd($subtotal, $quote['line_total'], 2);
        }

        return ['items' => $items, 'subtotal' => $subtotal, 'discount_total' => '0.00',
            'shipping_total' => '0.00', 'grand_total' => $subtotal];
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $actorId, string $source = 'admin'): SalesOrder
    {
        $channel = $data['sales_channel'] ?? 'retail';
        if (! in_array($channel, ['retail', 'dealer'], true)) {
            $this->conflict('UNSUPPORTED_CHANNEL');
        }
        if ($channel === 'dealer' && ! in_array($source, ['quick_order', 'dealer_excel'], true)) {
            $this->conflict('UNSUPPORTED_CHANNEL');
        }
        if (! in_array($source, ['admin', 'cart', 'quick_order', 'dealer_excel'], true)
            || ($channel === 'retail' && in_array($source, ['quick_order', 'dealer_excel'], true))) {
            $this->conflict('UNSUPPORTED_ORDER_SOURCE');
        }
        $fingerprint = $channel === 'dealer' ? $data['request_fingerprint']
            : $this->fingerprint($source === 'admin' ? [$actorId, $data] : [$actorId, $source, $data]);
        $existing = SalesOrder::query()->where('creation_operation_key', $data['operation_key'])->first();
        if ($existing !== null) {
            return $this->creationReplay($existing, $fingerprint);
        }
        try {
            return DB::transaction(function () use ($data, $actorId, $fingerprint, $source, $channel): SalesOrder {
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                if ($warehouse->status !== 'active') {
                    $this->conflict('WAREHOUSE_INACTIVE');
                }
                $context = null;
                if ($channel === 'dealer') {
                    if ((int) $data['buyer_user_id'] !== $actorId) {
                        $this->conflict('DEALER_ORDER_CONTEXT_INVALID');
                    }
                    $context = $this->dealerEffectivePricing->context(
                        User::query()->findOrFail($actorId),
                        DealerAccount::query()->findOrFail($data['dealer_account_id']),
                    );
                }
                $skus = array_map(static fn (array $row): string => strtoupper($row['sku']), $data['items']);
                $variants = ProductVariant::query()->with(['product.images', 'unit'])->whereIn('sku', $skus)->get()->keyBy('sku');
                $dealerPrices = $context === null ? [] : $this->dealerPricing->pricesFor($variants->values(), $context['tier']);
                $quotes = [];
                foreach ($data['items'] as $row) {
                    $variant = $variants->get(strtoupper($row['sku']));
                    if ($variant === null) {
                        $this->conflict('SKU_UNAVAILABLE');
                    }
                    $quotes[] = $this->quote($variant, (string) $row['quantity'], $data['currency'],
                        $context['tier'] ?? null, $context === null ? null : ($dealerPrices[$variant->id] ?? null));
                }
                $subtotal = $this->sum($quotes);
                $order = SalesOrder::create([
                    'order_code' => 'TMP'.bin2hex(random_bytes(10)),
                    'creation_operation_key' => $data['operation_key'],
                    'creation_fingerprint' => $fingerprint,
                    'sales_channel' => $channel, 'order_source' => $source,
                    'external_reference' => $data['external_reference'] ?? null,
                    'external_reference_normalized' => $data['external_reference_normalized'] ?? null,
                    'buyer_user_id' => $data['buyer_user_id'], 'warehouse_id' => $warehouse->id,
                    'dealer_account_id' => $context['account']->id ?? null,
                    'dealer_code_snapshot' => $context['account']->code ?? null,
                    'dealer_name_snapshot' => $context['account']->legal_name ?? null,
                    'effective_tier_id_snapshot' => $context['tier']->id ?? null,
                    'effective_tier_code_snapshot' => $context['tier']->code ?? null,
                    'effective_tier_name_snapshot' => $context['tier']->name ?? null,
                    'tier_source_snapshot' => $context['resolution']['source'] ?? null,
                    'tier_override_id_snapshot' => $context['resolution']['override']['id'] ?? null,
                    'currency' => $data['currency'],
                    'recipient_name' => $data['recipient_name'], 'recipient_phone' => $data['recipient_phone'],
                    'recipient_email' => $data['recipient_email'] ?? null,
                    'shipping_address_line1' => $data['shipping_address_line1'],
                    'shipping_address_line2' => $data['shipping_address_line2'] ?? null,
                    'shipping_city' => $data['shipping_city'], 'shipping_province' => $data['shipping_province'],
                    'shipping_province_code' => $data['shipping_province_code'] ?? null,
                    'shipping_ward_code' => $data['shipping_ward_code'] ?? null,
                    'shipping_ward' => $data['shipping_ward'] ?? null,
                    'shipping_district' => $data['shipping_district'] ?? null,
                    'shipping_country' => $data['shipping_country'],
                    'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
                    'delivery_note' => $data['delivery_note'] ?? null,
                    'order_status' => 'draft', 'payment_status' => 'unpaid',
                    'payment_method' => $data['payment_method'] ?? null,
                    'fulfillment_status' => 'unfulfilled', 'pricing_context_snapshot' => $channel,
                    'subtotal' => $subtotal, 'grand_total' => $subtotal,
                    'price_resolution_fingerprint' => $this->fingerprint(array_column($quotes, 'price_resolution_fingerprint')),
                    'created_by' => $actorId,
                ]);
                $order->update(['order_code' => 'ORD'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT)]);
                foreach ($quotes as $quote) {
                    $order->items()->create($quote);
                }
                $order->histories()->create(['actor_user_id' => $actorId, 'event_type' => 'created',
                    'to_status' => 'draft']);
                $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_SALES_ORDER, $order, 'Sales Order draft created', [], [], [
                    'order_code' => $order->order_code, 'item_count' => count($quotes), 'subtotal' => $subtotal,
                    'sales_channel' => $channel, 'order_source' => $source, 'dealer_account_id' => $order->dealer_account_id,
                ]);

                return $order;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = SalesOrder::query()->where('creation_operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    return $this->creationReplay($existing, $fingerprint);
                }
            }
            throw $exception;
        }
    }

    public function repriceDraft(SalesOrder $order, string $key, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'reprice', ['actor_id' => $actorId], function (SalesOrder $locked) use ($actorId): void {
            $this->requireStatus($locked, ['draft']);
            if ($locked->sales_channel !== 'retail') {
                $this->conflict('UNSUPPORTED_CHANNEL');
            }
            $quotes = [];
            foreach ($locked->items->where('is_gift', false) as $item) {
                $quotes[$item->id] = $this->quote(ProductVariant::query()->with(['product.images', 'unit'])->findOrFail($item->product_variant_id), $item->quantity, $locked->currency);
            }
            foreach ($locked->items->where('is_gift', false) as $item) {
                $item->update($quotes[$item->id]);
            }
            $subtotal = $this->sum(array_values($quotes));
            $locked->update(['subtotal' => $subtotal, 'grand_total' => $subtotal,
                'price_resolution_fingerprint' => $this->fingerprint(array_column(array_values($quotes), 'price_resolution_fingerprint'))]);
            $this->audit->log('REPRICE', AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order draft repriced', [], [], ['actor_id' => $actorId, 'subtotal' => $subtotal]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateRetailDraft(SalesOrder $order, array $data, int $actorId): SalesOrder
    {
        return $this->transition($order, $data['operation_key'], 'update_retail_draft',
            ['actor_id' => $actorId, 'data' => $data], function (SalesOrder $locked) use ($data, $actorId): void {
                $this->requireStatus($locked, ['draft']);
                if ($locked->sales_channel !== 'retail' || $locked->order_source !== 'admin'
                    || $data['sales_channel'] !== 'retail') {
                    $this->conflict('UNSUPPORTED_CHANNEL');
                }
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                if ($warehouse->status !== 'active') {
                    $this->conflict('WAREHOUSE_INACTIVE');
                }
                $buyer = User::query()->findOrFail($data['buyer_user_id']);
                if (! $buyer->isCustomer()) {
                    $this->conflict('BUYER_INVALID');
                }
                $skus = array_map(static fn (array $item): string => Sku::normalize($item['sku']), $data['items']);
                $variants = ProductVariant::query()->with(['product.images', 'unit'])
                    ->whereIn('sku', $skus)->get()->keyBy('sku');
                $quotes = [];
                foreach ($data['items'] as $index => $item) {
                    $variant = $variants->get($skus[$index]);
                    if ($variant === null) {
                        $this->conflict('SKU_UNAVAILABLE');
                    }
                    $quotes[] = $this->quote($variant, (string) $item['quantity'], 'VND');
                }
                $subtotal = $this->sum($quotes);
                $locked->update([
                    'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
                    'recipient_name' => $data['recipient_name'], 'recipient_phone' => $data['recipient_phone'],
                    'recipient_email' => $data['recipient_email'] ?? null,
                    'shipping_address_line1' => $data['shipping_address_line1'],
                    'shipping_address_line2' => $data['shipping_address_line2'] ?? null,
                    'shipping_city' => $data['shipping_city'],
                    'shipping_district' => $data['shipping_district'] ?? null,
                    'shipping_province' => $data['shipping_province'],
                    'shipping_country' => $data['shipping_country'],
                    'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
                    'delivery_note' => $data['delivery_note'] ?? null,
                    'payment_method' => $data['payment_method'] ?? null,
                    'subtotal' => $subtotal, 'grand_total' => $subtotal,
                    'price_resolution_fingerprint' => $this->fingerprint(array_column($quotes, 'price_resolution_fingerprint')),
                ]);
                $locked->items()->delete();
                foreach ($quotes as $quote) {
                    $locked->items()->create($quote);
                }
                $this->history($locked, 'draft_updated', 'draft', 'draft', $actorId);
                $this->audit->log('UPDATE', AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order draft updated',
                    metadata: ['actor_id' => $actorId, 'subtotal' => $subtotal]);
            });
    }

    public function submitRetail(SalesOrder $order, string $key, int $actorId, ?string $promotionCode = null, ?string $voucherCode = null): SalesOrder
    {
        return $this->transition($order, $key, 'submit_retail', ['actor_id' => $actorId, 'promotion_code' => $promotionCode, 'voucher_code' => $voucherCode],
            function (SalesOrder $locked) use ($actorId, $promotionCode, $voucherCode): void {
                if ($locked->sales_channel !== 'retail' || $locked->order_source !== 'cart') {
                    $this->conflict('UNSUPPORTED_CHANNEL');
                }
                $this->requireStatus($locked, ['draft']);
                $changes = ['order_status' => 'pending'];
                $locked->update($changes);
                if ($promotionCode !== null) {
                    $this->promotions->redeem($locked, $promotionCode);
                }
                if ($voucherCode !== null) {
                    $this->salesVouchers->redeem($locked, $voucherCode);
                }
                if ($locked->promotion_gift_snapshot !== null) {
                    $locked->unsetRelation('items');
                    $locked->load('items');
                    $this->inventory->reserve($locked);
                    $locked->update(['fulfillment_status' => 'reserved']);
                }
                $this->history($locked, 'submitted', 'draft', 'pending', $actorId);
            });
    }

    public function retailAdvance(SalesOrder $order, string $key, string $target, int $actorId, ?string $note = null): SalesOrder
    {
        return $this->transition($order, $key, 'retail_'.$target,
            ['actor_id' => $actorId, 'note' => $note], function (SalesOrder $locked) use ($target, $actorId, $note): void {
                if ($locked->sales_channel !== 'retail' || $locked->order_source !== 'cart') {
                    $this->conflict('UNSUPPORTED_CHANNEL');
                }
                $expected = ['preparing' => 'confirmed', 'shipping' => 'preparing',
                    'delivered' => 'shipping'];
                if (! isset($expected[$target])) {
                    $this->conflict('ORDER_INVALID_STATE');
                }
                $this->requireStatus($locked, [$expected[$target]]);
                if ($target === 'shipping') {
                    $quantities = $locked->items->mapWithKeys(fn ($item): array => [$item->id => $item->quantity])->all();
                    $this->inventory->fulfill($locked, $quantities, $actorId);
                }
                $changes = ['order_status' => $target];
                if ($target === 'shipping') {
                    $changes['fulfillment_status'] = 'fulfilled';
                }
                $locked->update($changes);
                $this->history($locked, $target, $expected[$target], $target, $actorId, $note);
                if ($target === 'delivered') {
                    $this->completeIfPaid($locked, $actorId);
                }
            });
    }

    public function changeRetailWarehouse(SalesOrder $order, string $key, int $warehouseId, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'retail_warehouse',
            ['warehouse_id' => $warehouseId, 'actor_id' => $actorId],
            function (SalesOrder $locked) use ($warehouseId, $actorId): void {
                if ($locked->sales_channel !== 'retail' || $locked->order_source !== 'cart') {
                    $this->conflict('UNSUPPORTED_CHANNEL');
                }
                $this->requireStatus($locked, ['pending', 'confirmed', 'preparing']);
                if ($locked->warehouse_id === $warehouseId) {
                    return;
                }
                $destination = Warehouse::query()->findOrFail($warehouseId);
                if ($destination->status !== 'active') {
                    $this->conflict('WAREHOUSE_INACTIVE');
                }
                $previousWarehouse = $locked->warehouse_id;
                if ($locked->order_status !== 'pending' || $locked->reservations()->exists()) {
                    $this->inventory->relocate($locked, $destination);
                } else {
                    $this->inventory->assertAvailable($locked, $destination);
                }
                $locked->update(['warehouse_id' => $warehouseId]);
                $this->history($locked, 'warehouse_changed', $locked->order_status, $locked->order_status,
                    $actorId, $previousWarehouse.' → '.$warehouseId);
            });
    }

    public function confirm(SalesOrder $order, string $key, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'confirm', ['actor_id' => $actorId], function (SalesOrder $locked) use ($actorId): void {
            $this->requireStatus($locked, $locked->sales_channel === 'retail' ? ['draft', 'pending'] : ['draft']);
            $wasPending = $locked->order_status === 'pending';
            $tier = null;
            if ($locked->sales_channel === 'dealer') {
                $account = DealerAccount::query()->lockForUpdate()->findOrFail($locked->dealer_account_id);
                $context = $this->dealerEffectivePricing->context(
                    User::query()->findOrFail($locked->buyer_user_id), $account,
                );
                if ($context['tier']->id !== $locked->effective_tier_id_snapshot
                    || $context['resolution']['source'] !== $locked->tier_source_snapshot
                    || ($context['resolution']['override']['id'] ?? null) !== $locked->tier_override_id_snapshot
                    || $locked->warehouse->status !== 'active') {
                    $this->conflict('DEALER_ORDER_CHANGED');
                }
                $tier = $context['tier'];
            }
            $productIds = ProductVariant::query()->whereIn('id', $locked->items->pluck('product_variant_id'))
                ->pluck('product_id')->unique();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            if (! $wasPending) {
                PriceList::query()->where('pricing_context', $locked->sales_channel)->orderBy('id')->lockForUpdate()->get();
            }
            $variants = ProductVariant::query()->with(['product.images', 'unit'])
                ->whereIn('id', $locked->items->pluck('product_variant_id'))->get()->keyBy('id');
            $dealerPrices = $tier === null ? [] : $this->dealerPricing->pricesFor($variants->values(), $tier);
            $fingerprints = [];
            foreach ($locked->items as $item) {
                $variant = $variants->get($item->product_variant_id);
                if ($variant === null) {
                    $this->conflict('SKU_UNAVAILABLE');
                }
                if ($item->is_gift) {
                    if ($variant->status !== 'active' || $variant->product->status !== 'active'
                        || ! $locked->permitsPromotionGift($variant, $item->source_promotion_id)
                        || ! $variant->track_inventory) {
                        $this->conflict('PROMOTION_GIFT_RULE_INVALID');
                    }

                    continue;
                }
                if ($wasPending) {
                    if ($variant->status !== 'active' || $variant->product->status !== 'active'
                        || $variant->product->gift_only || ! $variant->sellable_retail || ! $variant->track_inventory) {
                        $this->conflict('SKU_UNAVAILABLE');
                    }

                    continue;
                }
                $fingerprints[] = $this->quote($variant, $item->quantity, $locked->currency,
                    $tier, $tier === null ? null : ($dealerPrices[$variant->id] ?? null))['price_resolution_fingerprint'];
            }
            if (! $wasPending && $this->fingerprint($fingerprints) !== $locked->price_resolution_fingerprint) {
                $this->conflict($tier === null ? 'ORDER_PRICE_CHANGED' : 'DEALER_ORDER_CHANGED');
            }
            if (! $locked->reservations()->exists()) {
                $this->inventory->reserve($locked);
            }
            $locked->update(['order_status' => 'confirmed', 'fulfillment_status' => 'reserved',
                'confirmed_by' => $actorId, 'confirmed_at' => now()]);
            $this->history($locked, 'confirmed', $wasPending ? 'pending' : 'draft', 'confirmed', $actorId);
            $this->audit->log(AuditLogger::ACTION_CONFIRM, AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order confirmed', metadata: [
                'sales_channel' => $locked->sales_channel, 'dealer_account_id' => $locked->dealer_account_id,
            ]);
        });
    }

    public function cancel(SalesOrder $order, string $key, string $reason, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'cancel', ['reason' => $reason, 'actor_id' => $actorId], function (SalesOrder $locked) use ($reason, $actorId): void {
            if (bccomp($this->paymentSummaries->summary($locked)['refundable_amount'], '0', 2) !== 0
                || $this->paymentSummaries->hasUnsupportedLegacyMarker($locked, $this->paymentSummaries->paid($locked))) {
                $this->conflict('PAID_ORDER_REQUIRES_REFUND');
            }
            $this->requireStatus($locked, $locked->sales_channel === 'retail'
                ? ['draft', 'pending', 'confirmed', 'preparing'] : ['draft', 'confirmed']);
            $previousStatus = $locked->order_status;
            if (in_array($previousStatus, ['confirmed', 'preparing'], true) || $locked->reservations()->exists()) {
                $this->inventory->release($locked);
            }
            $locked->update(['order_status' => 'cancelled', 'fulfillment_status' => 'unfulfilled',
                'cancelled_by' => $actorId, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            if ($locked->voucher_id !== null) {
                $voucher = Voucher::query()->whereKey($locked->voucher_id)->lockForUpdate()->first();
                if ($voucher !== null && $voucher->status === Voucher::STATUS_USED) {
                    $voucher->update(['status' => $voucher->isExpired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE,
                        'used_at' => null]);
                }
            }
            $this->promotions->release($locked, $actorId);
            $this->salesVouchers->release($locked);
            $this->history($locked, 'cancelled', $previousStatus, 'cancelled', $actorId, $reason);
            $this->audit->log(AuditLogger::ACTION_CANCEL, AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order cancelled', [], [], ['reason' => $reason]);
        });
    }

    /** @param array<int, string> $quantities */
    public function fulfill(SalesOrder $order, string $key, array $quantities, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'fulfill', ['quantities' => $quantities, 'actor_id' => $actorId], function (SalesOrder $locked) use ($quantities, $actorId): void {
            if ($locked->sales_channel === 'retail' && $locked->order_source === 'cart') {
                $this->conflict('ORDER_INVALID_STATE');
            }
            $this->requireStatus($locked, ['confirmed', 'processing']);
            $previousStatus = $locked->order_status;
            foreach (array_keys($quantities) as $itemId) {
                $item = $locked->items->firstWhere('id', $itemId);
                if ($item === null) {
                    throw ValidationException::withMessages(['items' => 'Item does not belong to this Sales Order.']);
                }
                $precision = ProductVariant::query()->with('unit')->findOrFail($item->product_variant_id)->unit->decimal_precision;
                if (strlen(explode('.', $quantities[$itemId])[1] ?? '') > $precision) {
                    throw ValidationException::withMessages(['items' => 'Quantity exceeds the SKU Unit precision.']);
                }
            }
            $this->inventory->fulfill($locked, $quantities, $actorId);
            $remaining = '0.000';
            foreach ($locked->reservations()->get() as $reservation) {
                $remaining = bcadd($remaining, $this->inventory->remaining($reservation), 3);
            }
            $locked->update(bccomp($remaining, '0', 3) > 0
                ? ['order_status' => 'processing', 'fulfillment_status' => 'partially_fulfilled']
                : ['order_status' => 'delivered', 'fulfillment_status' => 'fulfilled']);
            $this->history($locked, 'fulfilled', $previousStatus, $locked->order_status, $actorId);
            $this->completeIfPaid($locked, $actorId);
            $this->audit->log('FULFILL', AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order fulfilled', [], [], ['item_count' => count($quantities)]);
        });
    }

    public function completeIfPaid(SalesOrder $order, int $actorId): bool
    {
        if ($order->order_status !== 'delivered' || $order->fulfillment_status !== 'fulfilled') {
            return false;
        }

        $summary = $this->paymentSummaries->summary($order);
        if (bccomp((string) $order->grand_total, '0', 2) <= 0
            || bccomp($summary['outstanding_amount'], '0', 2) > 0
            || $this->paymentSummaries->hasUnsupportedLegacyMarker($order, $summary['paid_amount'])) {
            return false;
        }

        $order->update(['order_status' => 'completed', 'completed_at' => now()]);
        $this->history($order, 'completed', 'delivered', 'completed', $actorId);

        return true;
    }

    /** @param array<string, mixed> $payload @param \Closure(SalesOrder): void $apply */
    private function transition(SalesOrder $order, string $key, string $type, array $payload, \Closure $apply): SalesOrder
    {
        $fingerprint = $this->fingerprint([$order->id, $type, $payload]);

        try {
            return DB::transaction(function () use ($order, $key, $type, $fingerprint, $apply): SalesOrder {
                $locked = SalesOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);
                $previous = DB::table('sales_order_operations')->where('operation_key', $key)->first();
                if ($previous !== null) {
                    if ($previous->sales_order_id !== $locked->id || $previous->operation_type !== $type || $previous->payload_fingerprint !== $fingerprint) {
                        $this->conflict('OPERATION_KEY_CONFLICT');
                    }

                    return $locked;
                }
                $apply($locked);
                DB::table('sales_order_operations')->insert([
                    'sales_order_id' => $locked->id, 'operation_key' => $key,
                    'operation_type' => $type, 'payload_fingerprint' => $fingerprint,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $locked->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062 && DB::table('sales_order_operations')->where('operation_key', $key)->exists()) {
                $this->conflict('OPERATION_KEY_CONFLICT');
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function quote(ProductVariant $variant, string $quantity, string $currency, ?DealerTier $tier = null, ?array $dealerPrice = null): array
    {
        $quantity = preg_replace('/\.0{1,3}$/', '', $quantity);
        if ($variant->product->gift_only) {
            $this->conflict('GIFT_ONLY_PRODUCT_NOT_PURCHASABLE');
        }
        if ($variant->status !== 'active' || $variant->product->status !== 'active'
            || ! ($tier === null ? $variant->sellable_retail : $variant->sellable_dealer) || ! $variant->track_inventory) {
            $this->conflict('SKU_UNAVAILABLE');
        }
        if (preg_match('/^[1-9][0-9]{0,14}$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive whole number.']);
        }
        if ($tier !== null && $dealerPrice === null) {
            $this->conflict('DEALER_PRICE_NOT_FOUND');
        }
        if ($tier !== null && bccomp($quantity, $dealerPrice['minimum_quantity'], 3) < 0) {
            $this->conflict('DEALER_MOQ_NOT_MET');
        }
        $price = $tier === null
            ? $this->pricing->resolve($variant, $currency, quantity: $quantity)
            : [...$dealerPrice, 'quantity' => $quantity, 'meets_moq' => true,
                'line_total' => bcadd(bcmul($dealerPrice['unit_price'], $quantity, 5), '0.005', 2)];
        $amount = bcadd(bcmul($price['unit_price'], $quantity, 5), '0.005', 2);

        $images = $variant->product->images->sortBy('sort_order');
        $image = $images->firstWhere('product_variant_id', $variant->id)
            ?? $images->firstWhere('product_variant_id', null) ?? $images->first();

        return [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'product_code_snapshot' => $variant->product->product_code,
            'product_name_snapshot' => $variant->product->name,
            'image_path_snapshot' => $image?->path,
            'sku_snapshot' => $variant->sku,
            'variant_name_snapshot' => $variant->variant_name,
            'unit_code_snapshot' => $variant->unit->code,
            'unit_name_snapshot' => $variant->unit->name,
            'quantity' => $quantity,
            'pricing_context_snapshot' => $tier === null ? 'retail' : 'dealer',
            'price_list_id' => $price['price_list_id'],
            'price_list_item_id' => $price['price_list_item_id'],
            'price_resolution_fingerprint' => $this->fingerprint($tier === null
                ? [$variant->id, $quantity, $currency, $price]
                : [$tier->id, $variant->id, $quantity, $currency, $price]),
            'minimum_quantity_snapshot' => $tier === null ? null : $price['minimum_quantity'],
            'unit_price_snapshot' => $price['unit_price'],
            'base_amount' => $amount,
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'line_total' => $amount,
        ];
    }

    /** @param list<array<string, mixed>> $quotes */
    private function sum(array $quotes): string
    {
        $subtotal = '0.00';
        foreach ($quotes as $quote) {
            $subtotal = bcadd($subtotal, $quote['line_total'], 2);
        }

        return $subtotal;
    }

    private function history(SalesOrder $order, string $event, ?string $from, ?string $to, int $actorId, ?string $note = null): void
    {
        $order->histories()->create(['actor_user_id' => $actorId, 'event_type' => $event,
            'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }

    /** @param array<mixed> $data */
    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function creationReplay(SalesOrder $order, string $fingerprint): SalesOrder
    {
        if ($order->creation_fingerprint !== $fingerprint) {
            $this->conflict('OPERATION_KEY_CONFLICT');
        }

        return $order;
    }

    /** @param list<string> $statuses */
    private function requireStatus(SalesOrder $order, array $statuses): void
    {
        if (! in_array($order->order_status, $statuses, true)) {
            $this->conflict('ORDER_INVALID_STATE');
        }
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
