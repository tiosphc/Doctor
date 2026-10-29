<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcurementService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function createOrder(array $data, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actorId): PurchaseOrder {
            $this->assertParties($data);
            $order = PurchaseOrder::create([
                'code' => 'PO-'.Str::ulid(), 'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'], 'status' => 'draft',
                'currency' => 'VND', 'total_amount' => '0.00',
                'note' => $data['note'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'supplier_order_reference' => $data['supplier_order_reference'] ?? null,
                'created_by' => $actorId,
            ]);
            $this->replaceItems($order, $data['items']);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_PROCUREMENT, $order, 'Purchase order drafted', [], ['code' => $order->code]);

            return $this->orderDetail($order);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateOrder(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'draft') {
                $this->conflict('PURCHASE_ORDER_NOT_DRAFT');
            }
            $merged = ['supplier_id' => $data['supplier_id'] ?? $order->supplier_id,
                'warehouse_id' => $data['warehouse_id'] ?? $order->warehouse_id];
            $this->assertParties($merged);
            $order->update([...$merged, 'note' => array_key_exists('note', $data) ? $data['note'] : $order->note,
                'expected_delivery_date' => array_key_exists('expected_delivery_date', $data) ? $data['expected_delivery_date'] : $order->expected_delivery_date,
                'supplier_order_reference' => array_key_exists('supplier_order_reference', $data) ? $data['supplier_order_reference'] : $order->supplier_order_reference]);
            if (isset($data['items'])) {
                $order->items()->delete();
                $this->replaceItems($order, $data['items']);
            }
            $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_PROCUREMENT, $order, 'Purchase order draft updated');

            return $this->orderDetail($order);
        }, 3);
    }

    public function issueOrder(PurchaseOrder $order, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actorId): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'draft') {
                $this->conflict('PURCHASE_ORDER_NOT_DRAFT');
            }
            $this->assertParties(['supplier_id' => $order->supplier_id, 'warehouse_id' => $order->warehouse_id]);
            if (! $order->items()->exists()) {
                $this->conflict('PURCHASE_ORDER_EMPTY');
            }
            $order->update(['status' => 'ordered', 'issued_by' => $actorId, 'issued_at' => now()]);
            $this->audit->log(AuditLogger::ACTION_CONFIRM, AuditLogger::MODULE_PROCUREMENT, $order, 'Purchase order issued');

            return $this->orderDetail($order);
        }, 3);
    }

    public function cancelOrder(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['draft', 'ordered'], true) || $order->receipts()->exists() || bccomp($order->paid_amount, '0', 2) > 0) {
                $this->conflict('PURCHASE_ORDER_CANNOT_CANCEL');
            }
            $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit->log(AuditLogger::ACTION_CANCEL, AuditLogger::MODULE_PROCUREMENT, $order, 'Purchase order cancelled');

            return $this->orderDetail($order);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function recordPayment(PurchaseOrder $order, array $data, int $actorId): PurchaseOrder
    {
        try {
            return DB::transaction(function () use ($order, $data, $actorId): PurchaseOrder {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                $existing = PurchaseOrderPayment::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->purchase_order_id !== $order->id || bccomp($existing->amount, $data['amount'], 2) !== 0
                        || $existing->payment_method !== $data['payment_method']
                        || strtotime($existing->paid_at->toDateTimeString()) !== strtotime((string) $data['paid_at'])
                        || $existing->external_reference !== ($data['external_reference'] ?? null)
                        || $existing->note !== ($data['note'] ?? null)
                        || $existing->recorded_by !== $actorId) {
                        $this->conflict('PROCUREMENT_OPERATION_KEY_CONFLICT');
                    }

                    return $this->orderDetail($order);
                }
                if (! in_array($order->status, ['ordered', 'partially_received', 'received'], true)) {
                    $this->conflict('PURCHASE_ORDER_NOT_ISSUED');
                }
                $paidAmount = bcadd($order->paid_amount, $data['amount'], 2);
                if (bccomp($paidAmount, $order->total_amount, 2) > 0) {
                    $this->conflict('PURCHASE_PAYMENT_EXCEEDS_TOTAL');
                }
                $payment = PurchaseOrderPayment::create([
                    'purchase_order_id' => $order->id, 'operation_key' => $data['operation_key'],
                    'amount' => $data['amount'], 'payment_method' => $data['payment_method'],
                    'paid_at' => $data['paid_at'], 'external_reference' => $data['external_reference'] ?? null,
                    'note' => $data['note'] ?? null, 'recorded_by' => $actorId,
                ]);
                $order->update(['paid_amount' => $paidAmount,
                    'payment_status' => bccomp($paidAmount, $order->total_amount, 2) === 0 ? 'paid' : 'partially_paid']);
                $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_PROCUREMENT, $payment,
                    'External supplier payment recorded', [], ['purchase_order_id' => $order->id,
                        'amount' => $payment->amount, 'payment_method' => $payment->payment_method]);

                return $this->orderDetail($order);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $this->conflict('PROCUREMENT_OPERATION_KEY_CONFLICT');
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function receive(PurchaseOrder $order, array $data, int $actorId): GoodsReceipt
    {
        $hash = $this->payloadHash($order->id, $actorId, $data, 'purchase_order_item_id', 'supplier_reference');

        try {
            return DB::transaction(function () use ($order, $data, $actorId, $hash): GoodsReceipt {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                $existing = GoodsReceipt::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->purchase_order_id !== $order->id || $existing->payload_hash !== $hash) {
                        $this->conflict('PROCUREMENT_OPERATION_KEY_CONFLICT');
                    }

                    return $this->receiptDetail($existing);
                }
                if (! in_array($order->status, ['ordered', 'partially_received'], true)) {
                    $this->conflict('PURCHASE_ORDER_NOT_RECEIVABLE');
                }
                $this->assertWarehouseActive($order->warehouse_id);
                $receipt = GoodsReceipt::create([
                    'code' => 'GR-'.Str::ulid(), 'purchase_order_id' => $order->id,
                    'operation_key' => $data['operation_key'], 'payload_hash' => $hash,
                    'supplier_reference' => $data['supplier_reference'] ?? null,
                    'note' => $data['note'] ?? null, 'received_by' => $actorId, 'received_at' => now(),
                ]);
                foreach ($data['items'] as $line) {
                    $item = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)
                        ->lockForUpdate()->find($line['purchase_order_item_id']);
                    if ($item === null) {
                        $this->conflict('PURCHASE_ORDER_ITEM_INVALID');
                    }
                    $quantity = $this->positiveQuantity($line['quantity'], $item->product_variant_id);
                    if (bccomp(bcadd($item->received_quantity, $quantity, 3), $item->ordered_quantity, 3) > 0) {
                        $this->conflict('PURCHASE_ORDER_OVER_RECEIPT');
                    }
                    $movement = $this->inventory->receive([
                        'warehouse_id' => $order->warehouse_id, 'product_variant_id' => $item->product_variant_id,
                        'quantity' => $this->inventoryQuantity($quantity), 'operation_key' => (string) Str::uuid(),
                        'reference_type' => 'GOODS_RECEIPT', 'reference_id' => (string) $receipt->id,
                        'reason_detail' => $receipt->code,
                        'unit_cost' => $item->unit_price,
                    ], $actorId);
                    GoodsReceiptItem::create([
                        'goods_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id,
                        'quantity' => $quantity, 'returned_quantity' => '0.000', 'stock_movement_id' => $movement->id,
                    ]);
                    $item->update(['received_quantity' => bcadd($item->received_quantity, $quantity, 3)]);
                }
                $incomplete = $order->items()->whereColumn('received_quantity', '<', 'ordered_quantity')->exists();
                $order->update(['status' => $incomplete ? 'partially_received' : 'received']);
                $this->audit->log(AuditLogger::ACTION_GOODS_RECEIPT, AuditLogger::MODULE_PROCUREMENT, $receipt, 'Purchase goods received', [], ['code' => $receipt->code, 'purchase_order_id' => $order->id]);

                return $this->receiptDetail($receipt);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = GoodsReceipt::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->purchase_order_id === $order->id && $existing->payload_hash === $hash) {
                        return $this->receiptDetail($existing);
                    }
                    $this->conflict('PROCUREMENT_OPERATION_KEY_CONFLICT');
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function returnGoods(PurchaseOrder $order, array $data, int $actorId): PurchaseReturn
    {
        $hash = $this->payloadHash($order->id, $actorId, $data, 'goods_receipt_item_id', 'reason');

        try {
            return DB::transaction(function () use ($order, $data, $actorId, $hash): PurchaseReturn {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                $existing = PurchaseReturn::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->purchase_order_id !== $order->id || $existing->payload_hash !== $hash) {
                        $this->conflict('PROCUREMENT_OPERATION_KEY_CONFLICT');
                    }

                    return $this->returnDetail($existing);
                }
                if (! in_array($order->status, ['partially_received', 'received'], true)) {
                    $this->conflict('PURCHASE_ORDER_HAS_NO_RECEIPTS');
                }
                $this->assertWarehouseActive($order->warehouse_id);
                $return = PurchaseReturn::create([
                    'code' => 'PR-'.Str::ulid(), 'purchase_order_id' => $order->id,
                    'operation_key' => $data['operation_key'], 'payload_hash' => $hash,
                    'reason' => $data['reason'], 'returned_by' => $actorId, 'returned_at' => now(),
                ]);
                foreach ($data['items'] as $line) {
                    $receiptItem = GoodsReceiptItem::query()->with('orderItem')
                        ->whereHas('receipt', fn ($query) => $query->where('purchase_order_id', $order->id))
                        ->lockForUpdate()->find($line['goods_receipt_item_id']);
                    if ($receiptItem === null) {
                        $this->conflict('GOODS_RECEIPT_ITEM_INVALID');
                    }
                    $quantity = $this->positiveQuantity($line['quantity'], $receiptItem->orderItem->product_variant_id);
                    if (bccomp(bcadd($receiptItem->returned_quantity, $quantity, 3), $receiptItem->quantity, 3) > 0) {
                        $this->conflict('PURCHASE_RETURN_EXCEEDS_RECEIPT');
                    }
                    $movement = $this->inventory->returnPurchase([
                        'warehouse_id' => $order->warehouse_id, 'product_variant_id' => $receiptItem->orderItem->product_variant_id,
                        'quantity' => $this->inventoryQuantity(bcsub('0', $quantity, 3)), 'operation_key' => (string) Str::uuid(),
                        'reference_type' => 'PURCHASE_RETURN', 'reference_id' => (string) $return->id,
                        'reason_code' => 'SUPPLIER_RETURN', 'reason_detail' => $data['reason'],
                    ], $actorId);
                    PurchaseReturnItem::create([
                        'purchase_return_id' => $return->id, 'goods_receipt_item_id' => $receiptItem->id,
                        'quantity' => $quantity, 'stock_movement_id' => $movement->id,
                    ]);
                    $receiptItem->update(['returned_quantity' => bcadd($receiptItem->returned_quantity, $quantity, 3)]);
                }
                $this->audit->log(AuditLogger::ACTION_PURCHASE_RETURN, AuditLogger::MODULE_PROCUREMENT, $return, 'Goods returned to supplier', [], ['code' => $return->code, 'purchase_order_id' => $order->id]);

                return $this->returnDetail($return);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = PurchaseReturn::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->purchase_order_id === $order->id && $existing->payload_hash === $hash) {
                        return $this->returnDetail($existing);
                    }
                    $this->conflict('PROCUREMENT_OPERATION_KEY_CONFLICT');
                }
            }
            throw $exception;
        }
    }

    public function orderDetail(PurchaseOrder $order): PurchaseOrder
    {
        return $order->load(['supplier:id,code,name,status', 'warehouse:id,code,name,status', 'items.variant.unit:id,name,symbol,decimal_precision',
            'receipts.items.orderItem:id,product_variant_id,sku_snapshot', 'returns.items.receiptItem:id,goods_receipt_id,purchase_order_item_id,quantity,returned_quantity',
            'payments:id,purchase_order_id,amount,payment_method,paid_at,external_reference,note,recorded_by']);
    }

    public function receiptDetail(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->load(['items.orderItem:id,product_variant_id,sku_snapshot,name_snapshot', 'items.movement:id,movement_type,quantity,reference_type,reference_id']);
    }

    public function returnDetail(PurchaseReturn $return): PurchaseReturn
    {
        return $return->load(['items.receiptItem.orderItem:id,product_variant_id,sku_snapshot', 'items.movement:id,movement_type,quantity,reference_type,reference_id']);
    }

    /** @param array<string, mixed> $data */
    private function assertParties(array $data): void
    {
        if (Supplier::query()->whereKey($data['supplier_id'])->where('status', 'active')->doesntExist()) {
            $this->conflict('SUPPLIER_INACTIVE');
        }
        $this->assertWarehouseActive($data['warehouse_id']);
    }

    private function assertWarehouseActive(int $warehouseId): void
    {
        if (Warehouse::query()->whereKey($warehouseId)->where('status', 'active')->doesntExist()) {
            $this->conflict('WAREHOUSE_INACTIVE');
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceItems(PurchaseOrder $order, array $items): void
    {
        $total = '0.00';
        foreach ($items as $line) {
            $variant = ProductVariant::query()->with(['product', 'unit'])->findOrFail($line['product_variant_id']);
            $quantity = $this->positiveQuantity($line['quantity'], $variant->id);
            if ($variant->status !== 'active' || $variant->product->status === 'inactive' || ! $variant->track_inventory) {
                $this->conflict('SKU_NOT_INVENTORY_CAPABLE');
            }
            $price = bcadd((string) $line['unit_price'], '0', 2);
            $lineTotal = bcadd(bcmul($quantity, $price, 5), '0', 2);
            $order->items()->create([
                'product_variant_id' => $variant->id, 'sku_snapshot' => $variant->sku,
                'name_snapshot' => $variant->variant_name ?: $variant->product->name,
                'ordered_quantity' => $quantity, 'received_quantity' => '0.000',
                'unit_price' => $price, 'line_total' => $lineTotal,
            ]);
            $total = bcadd($total, $lineTotal, 2);
        }
        $order->update(['total_amount' => $total]);
    }

    private function positiveQuantity(string $quantity, int $variantId): string
    {
        $variant = ProductVariant::query()->with('unit')->findOrFail($variantId);
        if (preg_match('/^[1-9][0-9]{0,14}$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive whole number.']);
        }

        return bcadd($quantity, '0', 3);
    }

    private function inventoryQuantity(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.');
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(int $orderId, int $actorId, array $data, string $itemKey, string $detailKey): string
    {
        $items = collect($data['items'])->map(fn (array $item): array => [
            'id' => (int) $item[$itemKey], 'quantity' => bcadd((string) $item['quantity'], '0', 3),
        ])->sortBy('id')->values()->all();

        return hash('sha256', json_encode([$orderId, $actorId, $data[$detailKey] ?? null, $data['note'] ?? null, $items], JSON_THROW_ON_ERROR));
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
