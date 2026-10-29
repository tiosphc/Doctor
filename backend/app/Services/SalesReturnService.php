<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\SalesReturnNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function returnable(SalesOrder $order): array
    {
        $counts = DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->where('sales_return.sales_order_id', $order->id)
            ->whereIn('sales_return.status', ['requested', 'approved', 'pending', 'completed'])
            ->selectRaw("item.sales_order_item_id, SUM(CASE WHEN sales_return.status = 'completed' THEN item.quantity ELSE 0 END) AS completed_quantity, SUM(CASE WHEN sales_return.status != 'completed' THEN item.quantity ELSE 0 END) AS active_quantity")
            ->groupBy('item.sales_order_item_id')->get()->keyBy('sales_order_item_id');

        return $order->items()->with(['reservation', 'productVariant.unit'])->orderBy('id')->get()
            ->map(function ($item) use ($counts): array {
                $fulfilled = $item->reservation?->consumed_quantity ?? '0.000';
                $returned = bcadd((string) ($counts->get($item->id)?->completed_quantity ?? '0'), '0', 3);
                $active = bcadd((string) ($counts->get($item->id)?->active_quantity ?? '0'), '0', 3);

                return ['item_id' => $item->id, 'product_name' => $item->product_name_snapshot,
                    'sku' => $item->sku_snapshot, 'ordered_quantity' => $item->quantity,
                    'is_gift' => $item->is_gift,
                    'fulfilled_quantity' => $fulfilled, 'returned_quantity' => $returned,
                    'pending_quantity' => $active,
                    'returnable_quantity' => bcsub(bcsub($fulfilled, $returned, 3), $active, 3),
                    'unit_code' => $item->unit_code_snapshot, 'unit_price' => $item->unit_price_snapshot,
                    'net_line_total' => $item->line_total, 'discount_amount' => $item->discount_amount,
                    'decimal_precision' => $item->productVariant->unit->decimal_precision];
            })->all();
    }

    /** @return array<string, mixed> */
    public function eligibility(SalesOrder $order): array
    {
        $days = max(1, (int) config('returns.window_days', 7));
        $delivery = $order->histories()->where('to_status', 'delivered')->oldest('id')->first()?->created_at;
        $deadline = $delivery === null ? null : CarbonImmutable::instance($delivery)->addDays($days);
        $reason = null;
        if ($order->order_status === 'cancelled') {
            $reason = 'ORDER_CANCELLED';
        } elseif (! in_array($order->order_status, ['delivered', 'completed'], true)
            || $order->fulfillment_status !== 'fulfilled' || $deadline === null) {
            $reason = 'ORDER_NOT_DELIVERED';
        } elseif (now()->greaterThan($deadline)) {
            $reason = 'RETURN_WINDOW_EXPIRED';
        } elseif (! collect($this->returnable($order))->contains(fn (array $item): bool => bccomp($item['returnable_quantity'], '0', 3) > 0)) {
            $reason = 'NOTHING_RETURNABLE';
        }

        return [
            'return_eligible' => $reason === null,
            'return_deadline' => $deadline?->toIso8601String(),
            'return_days_remaining' => $deadline === null ? null : max(0, (int) ceil(($deadline->timestamp - now()->timestamp) / 86400)),
            'return_ineligible_reason' => $reason,
            'return_window_days' => $days,
        ];
    }

    /** @param array<string, mixed> $data */
    public function complete(SalesOrder $order, array $data, int $actorId): SalesReturn
    {
        $customerRequest = ($data['processing_mode'] ?? 'immediate') === 'customer_request';
        $pendingInspection = ($data['processing_mode'] ?? 'immediate') !== 'immediate';
        $rows = collect($data['items'])->map(fn (array $row, int $index): array => [
            'input_index' => $index,
            'item_id' => (int) $row['item_id'],
            'quantity' => bcadd((string) $row['quantity'], '0', 3),
            'restock_quantity' => $pendingInspection ? '0.000' : bcadd((string) $row['restock_quantity'], '0', 3),
            'quantity_places' => strlen(explode('.', (string) $row['quantity'])[1] ?? ''),
            'restock_places' => $pendingInspection ? 0 : strlen(explode('.', (string) $row['restock_quantity'])[1] ?? ''),
            'non_restock_reason_code' => $pendingInspection ? null : ($row['non_restock_reason_code'] ?? null),
            'non_restock_note' => $pendingInspection ? null : (trim((string) ($row['non_restock_note'] ?? '')) ?: null),
        ])->sortBy('item_id')->values()->all();
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $normalizedRows = array_map(fn (array $row): array => array_intersect_key($row,
            array_flip(['item_id', 'quantity', 'restock_quantity', 'non_restock_reason_code', 'non_restock_note'])), $rows);
        $fingerprint = hash('sha256', json_encode($customerRequest
            ? [$order->id, trim($data['reason']), $note, $pendingInspection, $normalizedRows, $data['request_source'], $actorId, $data['reason_code']]
            : [$order->id, trim($data['reason']), $note, $pendingInspection, $normalizedRows], JSON_THROW_ON_ERROR));
        $legacyRows = array_map(fn (array $row): array => array_intersect_key($row,
            array_flip(['item_id', 'quantity', 'restock_quantity'])), $rows);
        $legacyFingerprint = hash('sha256', json_encode([$order->id, trim($data['reason']), $note, $legacyRows], JSON_THROW_ON_ERROR));
        $key = $data['operation_key'];

        try {
            return DB::transaction(function () use ($order, $data, $actorId, $rows, $note, $fingerprint, $legacyFingerprint, $key, $pendingInspection, $customerRequest): SalesReturn {
                $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
                $existing = SalesReturn::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $locked, $fingerprint, $legacyFingerprint);
                }
                if ($customerRequest) {
                    $eligibility = $this->eligibility($locked);
                    if (! $eligibility['return_eligible']) {
                        $this->conflict($eligibility['return_ineligible_reason']);
                    }
                }
                $available = collect($this->returnable($locked))->keyBy('item_id');
                $seen = [];
                foreach ($rows as $row) {
                    if (isset($seen[$row['item_id']])) {
                        $this->conflict('DUPLICATE_RETURN_ITEM');
                    }
                    $seen[$row['item_id']] = true;
                    $item = $available->get($row['item_id']);
                    if ($item === null) {
                        $this->conflict('RETURN_ITEM_NOT_IN_ORDER');
                    }
                    if ($row['quantity_places'] > $item['decimal_precision'] || $row['restock_places'] > $item['decimal_precision']) {
                        $this->conflict('RETURN_UNIT_PRECISION_INVALID');
                    }
                    if (bccomp($row['quantity'], '0', 3) <= 0 || bccomp($row['restock_quantity'], '0', 3) < 0
                        || bccomp($row['restock_quantity'], $row['quantity'], 3) > 0) {
                        $this->conflict('RETURN_QUANTITY_INVALID');
                    }
                    if (bccomp($row['quantity'], $item['returnable_quantity'], 3) > 0) {
                        $this->conflict('RETURN_QUANTITY_EXCEEDS_FULFILLED');
                    }
                    if (! $pendingInspection) {
                        $this->validateDisposition($row, "items.{$row['input_index']}", $row['quantity']);
                    }
                }
                $this->assertGiftEntitlementAfterReturn($locked, $rows);
                $salesReturn = SalesReturn::create([
                    'return_code' => 'RET'.Str::upper((string) Str::ulid()),
                    'sales_order_id' => $locked->id, 'warehouse_id' => $locked->warehouse_id,
                    'status' => $customerRequest ? 'requested' : 'pending',
                    'request_source' => $customerRequest ? $data['request_source'] : 'admin',
                    'requested_by_user_id' => $actorId,
                    'reason' => trim($data['reason']), 'reason_code' => $data['reason_code'] ?? null, 'note' => $note,
                    'processed_by_user_id' => $actorId,
                    'received_by_user_id' => $customerRequest ? null : $actorId,
                    'received_at' => $customerRequest ? null : now(), 'operation_key' => $key,
                    'request_fingerprint' => $fingerprint,
                ]);
                foreach ($rows as $row) {
                    $item = $locked->items()->findOrFail($row['item_id']);
                    $nonRestock = bcsub($row['quantity'], $row['restock_quantity'], 3);
                    $returnValue = $this->returnValue($item, $row['quantity']);
                    $returnItem = $salesReturn->items()->create([
                        'sales_order_item_id' => $item->id, 'quantity' => $row['quantity'],
                        'restock_quantity' => $row['restock_quantity'], 'non_restock_quantity' => $nonRestock,
                        'non_restock_reason_code' => bccomp($nonRestock, '0', 3) > 0 ? $row['non_restock_reason_code'] : null,
                        'non_restock_note' => bccomp($nonRestock, '0', 3) > 0 ? $row['non_restock_note'] : null,
                        'unit_value_snapshot' => $item->unit_price_snapshot,
                        'return_value_snapshot' => $returnValue,
                    ]);
                    if ($pendingInspection || bccomp($row['restock_quantity'], '0', 3) <= 0) {
                        continue;
                    }
                    $this->restock($salesReturn, $returnItem->id, $item->id, $item->product_variant_id, $row['restock_quantity'], $actorId);
                }
                if (! $pendingInspection) {
                    $salesReturn->update(['status' => 'completed', 'completed_at' => now()]);
                }
                $this->audit->log($customerRequest ? AuditLogger::ACTION_RETURN_REQUESTED : ($pendingInspection ? AuditLogger::ACTION_RETURN_RECEIVED : AuditLogger::ACTION_RETURN_COMPLETED), AuditLogger::MODULE_RETURN,
                    $salesReturn, $customerRequest ? 'Sales return requested' : ($pendingInspection ? 'Sales return awaiting inspection' : 'Sales return completed'), metadata: ['sales_order_id' => $locked->id,
                        'return_code' => $salesReturn->return_code, 'actor_id' => $actorId]);
                if ($customerRequest) {
                    User::query()->where('role', User::ROLE_ADMIN)->each(
                        fn (User $admin): mixed => $admin->notify(new SalesReturnNotification($salesReturn, 'requested', true))
                    );
                }

                return $salesReturn->load('items.salesOrderItem');
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = SalesReturn::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $order, $fingerprint, $legacyFingerprint);
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function process(SalesReturn $salesReturn, array $data, int $actorId): SalesReturn
    {
        $rows = collect($data['items'])->map(fn (array $row): array => [
            'return_item_id' => (int) $row['return_item_id'],
            'restock_quantity' => bcadd((string) $row['restock_quantity'], '0', 3),
            'restock_places' => strlen(explode('.', (string) $row['restock_quantity'])[1] ?? ''),
            'non_restock_reason_code' => $row['non_restock_reason_code'] ?? null,
            'non_restock_note' => trim((string) ($row['non_restock_note'] ?? '')) ?: null,
        ])->sortBy('return_item_id')->values()->all();
        $fingerprint = hash('sha256', json_encode([$salesReturn->id, $rows], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($salesReturn, $data, $actorId, $rows, $fingerprint): SalesReturn {
            SalesOrder::query()->lockForUpdate()->findOrFail($salesReturn->sales_order_id);
            $locked = SalesReturn::query()->lockForUpdate()->findOrFail($salesReturn->id);
            if ($locked->status === 'completed') {
                if ($locked->processing_operation_key === $data['operation_key']
                    && $locked->processing_fingerprint === $fingerprint) {
                    return $locked->load('items.salesOrderItem');
                }
                $this->conflict('RETURN_ALREADY_PROCESSED');
            }
            if ($locked->status !== 'pending' || $locked->processing_operation_key !== null) {
                $this->conflict('RETURN_NOT_PENDING_INSPECTION');
            }
            $items = $locked->items()->with('salesOrderItem.productVariant.unit')->get()->keyBy('id');
            if (count($rows) !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Phải xử lý tất cả mặt hàng trong phiếu trả.']);
            }
            $seen = [];
            foreach ($rows as $index => $row) {
                if (isset($seen[$row['return_item_id']]) || ! $items->has($row['return_item_id'])) {
                    throw ValidationException::withMessages(["items.{$index}.return_item_id" => 'Mặt hàng trả không thuộc phiếu hoặc bị trùng.']);
                }
                $seen[$row['return_item_id']] = true;
                $returnItem = $items->get($row['return_item_id']);
                $precision = $returnItem->salesOrderItem->productVariant->unit->decimal_precision;
                if ($row['restock_places'] > $precision || bccomp($row['restock_quantity'], $returnItem->quantity, 3) > 0) {
                    throw ValidationException::withMessages(["items.{$index}.restock_quantity" => 'Số nhập lại kho không hợp lệ.']);
                }
                $this->validateDisposition($row, "items.{$index}", $returnItem->quantity);
                $returnItem->update([
                    'return_value_snapshot' => $this->returnValue($returnItem->salesOrderItem, $returnItem->quantity),
                    'restock_quantity' => $row['restock_quantity'],
                    'non_restock_quantity' => bcsub($returnItem->quantity, $row['restock_quantity'], 3),
                    'non_restock_reason_code' => bccomp($row['restock_quantity'], $returnItem->quantity, 3) < 0 ? $row['non_restock_reason_code'] : null,
                    'non_restock_note' => bccomp($row['restock_quantity'], $returnItem->quantity, 3) < 0 ? $row['non_restock_note'] : null,
                ]);
                if (bccomp($row['restock_quantity'], '0', 3) > 0) {
                    $this->restock($locked, $returnItem->id, $returnItem->sales_order_item_id,
                        $returnItem->salesOrderItem->product_variant_id, $row['restock_quantity'], $actorId);
                }
            }
            $locked->update([
                'status' => 'completed', 'processed_by_user_id' => $actorId,
                'processing_operation_key' => $data['operation_key'], 'processing_fingerprint' => $fingerprint,
                'completed_at' => now(),
            ]);
            $this->audit->log(AuditLogger::ACTION_RETURN_COMPLETED, AuditLogger::MODULE_RETURN,
                $locked, 'Sales return inspection completed', metadata: ['sales_order_id' => $locked->sales_order_id,
                    'return_code' => $locked->return_code, 'actor_id' => $actorId, 'items' => $rows]);
            if (in_array($locked->request_source, ['dealer', 'retail'], true)) {
                $locked->requestedBy?->notify(new SalesReturnNotification($locked, 'completed'));
            }

            return $locked->load('items.salesOrderItem');
        }, 3);
    }

    public function approve(SalesReturn $salesReturn, int $actorId): SalesReturn
    {
        return $this->transitionRequest($salesReturn, 'requested', 'approved', $actorId);
    }

    public function reject(SalesReturn $salesReturn, string $reason, int $actorId): SalesReturn
    {
        return $this->transitionRequest($salesReturn, 'requested', 'rejected', $actorId, trim($reason));
    }

    public function receive(SalesReturn $salesReturn, int $actorId): SalesReturn
    {
        return $this->transitionRequest($salesReturn, 'approved', 'pending', $actorId);
    }

    private function transitionRequest(SalesReturn $salesReturn, string $from, string $to, int $actorId, ?string $reason = null): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $from, $to, $actorId, $reason): SalesReturn {
            SalesOrder::query()->lockForUpdate()->findOrFail($salesReturn->sales_order_id);
            $locked = SalesReturn::query()->lockForUpdate()->findOrFail($salesReturn->id);
            if ($locked->status !== $from || ! in_array($locked->request_source, ['dealer', 'retail'], true)) {
                $this->conflict('RETURN_INVALID_STATE');
            }
            $changes = ['status' => $to];
            if ($to === 'approved') {
                $changes += ['approved_by_user_id' => $actorId, 'approved_at' => now()];
            } elseif ($to === 'rejected') {
                $changes += ['rejected_by_user_id' => $actorId, 'rejected_at' => now(), 'rejection_reason' => $reason];
            } else {
                $changes += ['received_by_user_id' => $actorId, 'received_at' => now()];
            }
            $locked->update($changes);
            $action = match ($to) {
                'approved' => AuditLogger::ACTION_RETURN_APPROVED,
                'rejected' => AuditLogger::ACTION_RETURN_REJECTED,
                default => AuditLogger::ACTION_RETURN_RECEIVED,
            };
            $this->audit->log($action, AuditLogger::MODULE_RETURN, $locked,
                'Sales return '.$to, metadata: ['sales_order_id' => $locked->sales_order_id,
                    'return_code' => $locked->return_code, 'actor_id' => $actorId, 'reason' => $reason]);
            $locked->requestedBy?->notify(new SalesReturnNotification($locked, $to === 'pending' ? 'received' : $to));

            return $locked->load('items.salesOrderItem');
        }, 3);
    }

    /** @param array<string, mixed> $row */
    private function validateDisposition(array $row, string $field, string $quantity): void
    {
        if (bccomp($row['restock_quantity'], $quantity, 3) < 0 && blank($row['non_restock_reason_code'])) {
            throw ValidationException::withMessages(["{$field}.non_restock_reason_code" => 'Cần chọn lý do cho số lượng không nhập lại kho.']);
        }
        if (bccomp($row['restock_quantity'], $quantity, 3) < 0
            && $row['non_restock_reason_code'] === 'other' && blank($row['non_restock_note'])) {
            throw ValidationException::withMessages(["{$field}.non_restock_note" => 'Cần nhập ghi chú cho lý do khác.']);
        }
    }

    private function returnValue(SalesOrderItem $item, string $quantity): string
    {
        $priorReturns = DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->where('item.sales_order_item_id', $item->id)->where('sales_return.status', 'completed')
            ->get(['item.quantity', 'item.unit_value_snapshot', 'item.return_value_snapshot']);
        $priorQuantity = '0.000';
        $priorValue = '0.00';
        foreach ($priorReturns as $priorReturn) {
            $priorQuantity = bcadd($priorQuantity, $priorReturn->quantity, 3);
            $priorValue = bcadd($priorValue, $priorReturn->return_value_snapshot
                ?? bcadd(bcmul($priorReturn->quantity, $priorReturn->unit_value_snapshot, 5), '0.005', 2), 2);
        }
        $cumulativeQuantity = bcadd($priorQuantity, $quantity, 3);
        $cumulativeValue = bccomp($cumulativeQuantity, $item->quantity, 3) === 0
            ? $item->line_total
            : bcadd(bcdiv(bcmul($item->line_total, $cumulativeQuantity, 5), $item->quantity, 5), '0.005', 2);

        return bcsub($cumulativeValue, $priorValue, 2);
    }

    private function restock(SalesReturn $salesReturn, int $returnItemId, int $orderItemId, int $variantId, string $quantity, int $actorId): void
    {
        $balance = DB::table('inventory_balances')->where('warehouse_id', $salesReturn->warehouse_id)
            ->where('product_variant_id', $variantId)->lockForUpdate()->firstOrFail();
        $after = bcadd((string) $balance->on_hand_quantity, $quantity, 3);
        $shipments = DB::table('stock_movements')->where('movement_type', 'SALES_ORDER_SHIPMENT')
            ->where('reference_type', 'SALES_ORDER_ITEM')->where('reference_id', (string) $orderItemId)
            ->orderBy('id')->get(['quantity', 'cost_amount']);
        $shippedQuantity = '0.000';
        $shippedCost = '0.00';
        $costKnown = $shipments->isNotEmpty();
        foreach ($shipments as $shipment) {
            $shippedQuantity = bcadd($shippedQuantity, ltrim((string) $shipment->quantity, '-'), 3);
            if ($shipment->cost_amount === null) {
                $costKnown = false;
            } else {
                $shippedCost = bcadd($shippedCost, (string) $shipment->cost_amount, 2);
            }
        }
        $returnCost = $costKnown && bccomp($shippedQuantity, '0', 3) > 0
            ? bcdiv($shippedCost, $shippedQuantity, 6) : null;
        $priorCost = $balance->average_unit_cost === null ? null : (string) $balance->average_unit_cost;
        $averageCost = $returnCost === null || (bccomp((string) $balance->on_hand_quantity, '0', 3) > 0 && $priorCost === null)
            ? null : (bccomp((string) $balance->on_hand_quantity, '0', 3) === 0 ? $returnCost
                : bcdiv(bcadd(bcmul((string) $balance->on_hand_quantity, $priorCost, 9),
                    bcmul($quantity, $returnCost, 9), 9), $after, 6));
        $movementId = DB::table('stock_movements')->insertGetId([
            'warehouse_id' => $salesReturn->warehouse_id, 'product_variant_id' => $variantId,
            'movement_type' => 'SALES_RETURN', 'quantity' => $quantity,
            'before_on_hand_quantity' => $balance->on_hand_quantity, 'after_on_hand_quantity' => $after,
            'unit_cost' => $returnCost,
            'cost_amount' => $returnCost === null ? null : bcadd(bcmul($quantity, $returnCost, 9), '0.005', 2),
            'reference_type' => 'SALES_RETURN_ITEM', 'reference_id' => (string) $returnItemId,
            'operation_key' => (string) Str::uuid(), 'reason_code' => 'SALES_RETURN',
            'reason_detail' => $salesReturn->return_code, 'actor_user_id' => $actorId,
            'source' => 'admin', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_balances')->where('id', $balance->id)
            ->update(['on_hand_quantity' => $after, 'average_unit_cost' => $averageCost, 'updated_at' => now()]);
        $this->audit->log('SALES_RETURN', AuditLogger::MODULE_INVENTORY, StockMovement::findOrFail($movementId),
            'Returned goods restocked', metadata: ['sales_return_id' => $salesReturn->id,
                'sales_order_id' => $salesReturn->sales_order_id, 'sales_order_item_id' => $orderItemId,
                'quantity' => $quantity]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertGiftEntitlementAfterReturn(SalesOrder $order, array $rows): void
    {
        $snapshot = $order->promotion_gift_snapshot;
        if (! is_array($snapshot)) {
            return;
        }
        $paidQuantity = '0.000';
        $giftQuantity = '0.000';
        foreach ($order->items()->get() as $item) {
            if ($item->is_gift && $item->product_variant_id === (int) $snapshot['gift_variant_id']) {
                $giftQuantity = bcadd($giftQuantity, $item->quantity, 3);
            } elseif (! $item->is_gift && $item->product_id === (int) $snapshot['buy_product_id']
                && ($snapshot['buy_variant_id'] === null || $item->product_variant_id === (int) $snapshot['buy_variant_id'])) {
                $paidQuantity = bcadd($paidQuantity, $item->quantity, 3);
            }
        }
        $priorReturns = DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->join('sales_order_items as order_item', 'order_item.id', '=', 'item.sales_order_item_id')
            ->where('sales_return.sales_order_id', $order->id)->whereIn('sales_return.status', ['requested', 'approved', 'pending', 'completed'])
            ->get(['order_item.product_id', 'order_item.product_variant_id', 'order_item.is_gift', 'item.quantity']);
        foreach ($priorReturns as $returned) {
            if ($returned->is_gift && $returned->product_variant_id === (int) $snapshot['gift_variant_id']) {
                $giftQuantity = bcsub($giftQuantity, $returned->quantity, 3);
            } elseif (! $returned->is_gift && $returned->product_id === (int) $snapshot['buy_product_id']
                && ($snapshot['buy_variant_id'] === null || $returned->product_variant_id === (int) $snapshot['buy_variant_id'])) {
                $paidQuantity = bcsub($paidQuantity, $returned->quantity, 3);
            }
        }
        $items = $order->items()->get()->keyBy('id');
        foreach ($rows as $row) {
            $item = $items->get($row['item_id']);
            if ($item->is_gift && $item->product_variant_id === (int) $snapshot['gift_variant_id']) {
                $giftQuantity = bcsub($giftQuantity, $row['quantity'], 3);
            } elseif (! $item->is_gift && $item->product_id === (int) $snapshot['buy_product_id']
                && ($snapshot['buy_variant_id'] === null || $item->product_variant_id === (int) $snapshot['buy_variant_id'])) {
                $paidQuantity = bcsub($paidQuantity, $row['quantity'], 3);
            }
        }
        $minimum = (string) $snapshot['minimum_buy_quantity'];
        $multiplier = bccomp($paidQuantity, $minimum, 3) < 0 ? '0'
            : ($snapshot['repeat_per_multiple'] ? bcdiv($paidQuantity, $minimum, 0) : '1');
        $allowedGiftQuantity = bcmul($multiplier, (string) $snapshot['gift_quantity'], 3);
        if (bccomp($giftQuantity, $allowedGiftQuantity, 3) > 0) {
            $this->conflict('GIFT_RETURN_REQUIRED');
        }
    }

    private function replay(SalesReturn $salesReturn, SalesOrder $order, string $fingerprint, string $legacyFingerprint): SalesReturn
    {
        $isLegacyReplay = $salesReturn->request_source === null
            && $salesReturn->request_fingerprint === $legacyFingerprint;
        if ($salesReturn->sales_order_id !== $order->id
            || ($salesReturn->request_fingerprint !== $fingerprint && ! $isLegacyReplay)) {
            $this->conflict('RETURN_OPERATION_CONFLICT');
        }

        return $salesReturn->load('items.salesOrderItem');
    }

    private function conflict(string $code): never
    {
        $message = match ($code) {
            'RETURN_ALREADY_PROCESSED' => 'Phiếu trả hàng đã được xử lý.',
            'RETURN_NOT_PENDING_INSPECTION' => 'Phiếu trả hàng không ở trạng thái chờ kiểm tra.',
            'RETURN_QUANTITY_EXCEEDS_FULFILLED' => 'Số lượng trả vượt quá số đã giao hoặc đã được ghi nhận trả.',
            default => $code,
        };
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409));
    }
}
