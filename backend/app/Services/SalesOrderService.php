<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        private readonly RetailPricingService $pricing,
        private readonly InventoryReservationService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $actorId): SalesOrder
    {
        if (($data['sales_channel'] ?? 'retail') !== 'retail') {
            $this->conflict('UNSUPPORTED_CHANNEL');
        }
        $fingerprint = $this->fingerprint([$actorId, $data]);
        $existing = SalesOrder::query()->where('creation_operation_key', $data['operation_key'])->first();
        if ($existing !== null) {
            return $this->creationReplay($existing, $fingerprint);
        }
        try {
            return DB::transaction(function () use ($data, $actorId, $fingerprint): SalesOrder {
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                if ($warehouse->status !== 'active') {
                    $this->conflict('WAREHOUSE_INACTIVE');
                }
                $quotes = [];
                foreach ($data['items'] as $row) {
                    $variant = ProductVariant::query()->with(['product', 'unit'])->where('sku', strtoupper($row['sku']))->first();
                    if ($variant === null) {
                        $this->conflict('SKU_UNAVAILABLE');
                    }
                    $quotes[] = $this->quote($variant, (string) $row['quantity'], $data['currency']);
                }
                $subtotal = $this->sum($quotes);
                $order = SalesOrder::create([
                    'order_code' => 'TMP'.bin2hex(random_bytes(10)),
                    'creation_operation_key' => $data['operation_key'],
                    'creation_fingerprint' => $fingerprint,
                    'sales_channel' => 'retail', 'order_source' => 'admin',
                    'buyer_user_id' => $data['buyer_user_id'], 'warehouse_id' => $warehouse->id,
                    'currency' => $data['currency'],
                    'recipient_name' => $data['recipient_name'], 'recipient_phone' => $data['recipient_phone'],
                    'recipient_email' => $data['recipient_email'] ?? null,
                    'shipping_address_line1' => $data['shipping_address_line1'],
                    'shipping_address_line2' => $data['shipping_address_line2'] ?? null,
                    'shipping_city' => $data['shipping_city'], 'shipping_province' => $data['shipping_province'],
                    'shipping_country' => $data['shipping_country'],
                    'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
                    'delivery_note' => $data['delivery_note'] ?? null,
                    'order_status' => 'draft', 'payment_status' => 'unpaid',
                    'fulfillment_status' => 'unfulfilled', 'pricing_context_snapshot' => 'retail',
                    'subtotal' => $subtotal, 'grand_total' => $subtotal,
                    'price_resolution_fingerprint' => $this->fingerprint(array_column($quotes, 'price_resolution_fingerprint')),
                    'created_by' => $actorId,
                ]);
                $order->update(['order_code' => 'ORD'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT)]);
                foreach ($quotes as $quote) {
                    $order->items()->create($quote);
                }
                $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_SALES_ORDER, $order, 'Sales Order draft created', [], [], [
                    'order_code' => $order->order_code, 'item_count' => count($quotes), 'subtotal' => $subtotal,
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
            $quotes = [];
            foreach ($locked->items as $item) {
                $quotes[$item->id] = $this->quote(ProductVariant::query()->with(['product', 'unit'])->findOrFail($item->product_variant_id), $item->quantity, $locked->currency);
            }
            foreach ($locked->items as $item) {
                $item->update($quotes[$item->id]);
            }
            $subtotal = $this->sum(array_values($quotes));
            $locked->update(['subtotal' => $subtotal, 'grand_total' => $subtotal,
                'price_resolution_fingerprint' => $this->fingerprint(array_column(array_values($quotes), 'price_resolution_fingerprint'))]);
            $this->audit->log('REPRICE', AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order draft repriced', [], [], ['actor_id' => $actorId, 'subtotal' => $subtotal]);
        });
    }

    public function confirm(SalesOrder $order, string $key, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'confirm', ['actor_id' => $actorId], function (SalesOrder $locked) use ($actorId): void {
            $this->requireStatus($locked, ['draft']);
            $productIds = ProductVariant::query()->whereIn('id', $locked->items->pluck('product_variant_id'))
                ->pluck('product_id')->unique();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            PriceList::query()->where('pricing_context', 'retail')->orderBy('id')->lockForUpdate()->get();
            $fingerprints = [];
            foreach ($locked->items as $item) {
                $variant = ProductVariant::query()->with(['product', 'unit'])->findOrFail($item->product_variant_id);
                $fingerprints[] = $this->quote($variant, $item->quantity, $locked->currency)['price_resolution_fingerprint'];
            }
            if ($this->fingerprint($fingerprints) !== $locked->price_resolution_fingerprint) {
                $this->conflict('ORDER_PRICE_CHANGED');
            }
            $this->inventory->reserve($locked);
            $locked->update(['order_status' => 'confirmed', 'fulfillment_status' => 'reserved',
                'confirmed_by' => $actorId, 'confirmed_at' => now()]);
            $this->audit->log(AuditLogger::ACTION_CONFIRM, AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order confirmed');
        });
    }

    public function cancel(SalesOrder $order, string $key, string $reason, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'cancel', ['reason' => $reason, 'actor_id' => $actorId], function (SalesOrder $locked) use ($reason, $actorId): void {
            $this->requireStatus($locked, ['draft', 'confirmed']);
            if ($locked->order_status === 'confirmed') {
                $this->inventory->release($locked);
            }
            $locked->update(['order_status' => 'cancelled', 'fulfillment_status' => 'unfulfilled',
                'cancelled_by' => $actorId, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit->log(AuditLogger::ACTION_CANCEL, AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order cancelled', [], [], ['reason' => $reason]);
        });
    }

    /** @param array<int, string> $quantities */
    public function fulfill(SalesOrder $order, string $key, array $quantities, int $actorId): SalesOrder
    {
        return $this->transition($order, $key, 'fulfill', ['quantities' => $quantities, 'actor_id' => $actorId], function (SalesOrder $locked) use ($quantities, $actorId): void {
            $this->requireStatus($locked, ['confirmed', 'processing']);
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
                : ['order_status' => 'completed', 'fulfillment_status' => 'fulfilled', 'completed_at' => now()]);
            $this->audit->log('FULFILL', AuditLogger::MODULE_SALES_ORDER, $locked, 'Sales Order fulfilled', [], [], ['item_count' => count($quantities)]);
        });
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
    private function quote(ProductVariant $variant, string $quantity, string $currency): array
    {
        $quantity = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
        if ($variant->status !== 'active' || $variant->product->status !== 'active'
            || ! $variant->sellable_retail || ! $variant->track_inventory) {
            $this->conflict('SKU_UNAVAILABLE');
        }
        if (preg_match('/^[1-9][0-9]{0,14}(?:\.[0-9]{1,3})?$/', $quantity) !== 1
            || strlen(explode('.', $quantity)[1] ?? '') > $variant->unit->decimal_precision) {
            throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the SKU Unit precision.']);
        }
        $price = $this->pricing->resolve($variant, $currency, quantity: $quantity);
        $amount = bcadd(bcmul($price['unit_price'], $quantity, 5), '0.005', 2);

        return [
            'product_variant_id' => $variant->id,
            'product_code_snapshot' => $variant->product->product_code,
            'product_name_snapshot' => $variant->product->name,
            'sku_snapshot' => $variant->sku,
            'variant_name_snapshot' => $variant->variant_name,
            'unit_code_snapshot' => $variant->unit->code,
            'unit_name_snapshot' => $variant->unit->name,
            'quantity' => $quantity,
            'pricing_context_snapshot' => 'retail',
            'price_list_id' => $price['price_list_id'],
            'price_list_item_id' => $price['price_list_item_id'],
            'price_resolution_fingerprint' => $this->fingerprint([$variant->id, $quantity, $currency, $price]),
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
