<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryReservationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function reserve(SalesOrder $order): void
    {
        $this->lockWarehouse($order, true);
        $items = $order->items->sortBy('product_variant_id')->values();
        $balances = [];
        foreach ($items as $item) {
            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($item->product_variant_id);
            if ($variant->status !== 'active' || $variant->product->status !== 'active' || ! $variant->sellable_retail || ! $variant->track_inventory) {
                $this->conflict('SKU_UNAVAILABLE');
            }
            $balance = DB::table('inventory_balances')->where('warehouse_id', $order->warehouse_id)
                ->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
            $available = $balance === null ? '0.000' : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            if (bccomp($available, $item->quantity, 3) < 0) {
                $this->conflict('INSUFFICIENT_STOCK', [
                    'sku' => $item->sku_snapshot, 'requested' => $item->quantity,
                    'available' => $available, 'warehouse_id' => $order->warehouse_id,
                ]);
            }
            $balances[$item->id] = $balance;
        }
        foreach ($items as $item) {
            $balance = $balances[$item->id];
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'reserved_quantity' => bcadd((string) $balance->reserved_quantity, $item->quantity, 3),
                'updated_at' => now(),
            ]);
            $reservation = InventoryReservation::create([
                'sales_order_id' => $order->id, 'sales_order_item_id' => $item->id,
                'warehouse_id' => $order->warehouse_id, 'product_variant_id' => $item->product_variant_id,
                'original_quantity' => $item->quantity, 'operation_key' => (string) Str::uuid(), 'status' => 'active',
            ]);
            $this->audit->log('RESERVE', AuditLogger::MODULE_SALES_ORDER, $reservation, 'Order inventory reserved', [], [], [
                'order_id' => $order->id, 'sku' => $item->sku_snapshot, 'quantity' => $item->quantity,
            ]);
        }
    }

    public function release(SalesOrder $order): void
    {
        $this->lockWarehouse($order, false);
        $reservations = InventoryReservation::query()->where('sales_order_id', $order->id)
            ->orderBy('product_variant_id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            $remaining = $this->remaining($reservation);
            if (bccomp($remaining, '0', 3) === 0) {
                continue;
            }
            $balance = DB::table('inventory_balances')->where('warehouse_id', $order->warehouse_id)
                ->where('product_variant_id', $reservation->product_variant_id)->lockForUpdate()->firstOrFail();
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'reserved_quantity' => bcsub((string) $balance->reserved_quantity, $remaining, 3),
                'updated_at' => now(),
            ]);
            $reservation->update(['released_quantity' => bcadd($reservation->released_quantity, $remaining, 3), 'status' => 'released']);
            $this->audit->log('RELEASE', AuditLogger::MODULE_SALES_ORDER, $reservation, 'Order inventory reservation released', [], [], [
                'order_id' => $order->id, 'quantity' => $remaining,
            ]);
        }
    }

    /** @param array<int, string> $quantities */
    public function fulfill(SalesOrder $order, array $quantities, int $actorId): void
    {
        $this->lockWarehouse($order, false);
        $reservations = InventoryReservation::query()->where('sales_order_id', $order->id)
            ->orderBy('product_variant_id')->lockForUpdate()->get()->keyBy('sales_order_item_id');
        foreach ($order->items->sortBy('product_variant_id') as $item) {
            $quantity = $quantities[$item->id] ?? null;
            if ($quantity === null) {
                continue;
            }
            $reservation = $reservations->get($item->id);
            if ($reservation === null || bccomp($quantity, $this->remaining($reservation), 3) > 0) {
                $this->conflict('FULFILLMENT_EXCEEDS_RESERVATION');
            }
            $balance = DB::table('inventory_balances')->where('warehouse_id', $order->warehouse_id)
                ->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->firstOrFail();
            if (bccomp((string) $balance->on_hand_quantity, $quantity, 3) < 0
                || bccomp((string) $balance->reserved_quantity, $quantity, 3) < 0) {
                $this->conflict('INSUFFICIENT_STOCK');
            }
            $after = bcsub((string) $balance->on_hand_quantity, $quantity, 3);
            $movementId = DB::table('stock_movements')->insertGetId([
                'warehouse_id' => $order->warehouse_id, 'product_variant_id' => $item->product_variant_id,
                'movement_type' => 'SALES_ORDER_SHIPMENT', 'quantity' => bcsub('0', $quantity, 3),
                'before_on_hand_quantity' => $balance->on_hand_quantity, 'after_on_hand_quantity' => $after,
                'reference_type' => 'SALES_ORDER_ITEM', 'reference_id' => (string) $item->id,
                'operation_key' => (string) Str::uuid(), 'actor_user_id' => $actorId,
                'source' => 'admin', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'on_hand_quantity' => $after,
                'reserved_quantity' => bcsub((string) $balance->reserved_quantity, $quantity, 3),
                'updated_at' => now(),
            ]);
            $consumed = bcadd($reservation->consumed_quantity, $quantity, 3);
            $reservation->update([
                'consumed_quantity' => $consumed,
                'status' => bccomp($consumed, $reservation->original_quantity, 3) === 0 ? 'consumed' : 'partially_consumed',
            ]);
            $this->audit->log('CONSUME', AuditLogger::MODULE_SALES_ORDER, $reservation, 'Order reservation consumed', [], [], [
                'order_id' => $order->id, 'quantity' => $quantity, 'movement_id' => $movementId,
            ]);
            $this->audit->log('SALES_ORDER_SHIPMENT', AuditLogger::MODULE_INVENTORY, StockMovement::query()->findOrFail($movementId), 'Sales Order shipment', [], [], [
                'order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $quantity,
            ]);
        }
    }

    public function remaining(InventoryReservation $reservation): string
    {
        return bcsub(bcsub($reservation->original_quantity, $reservation->consumed_quantity, 3), $reservation->released_quantity, 3);
    }

    private function lockWarehouse(SalesOrder $order, bool $requireActive): void
    {
        $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($order->warehouse_id);
        if ($requireActive && $warehouse->status !== 'active') {
            $this->conflict('WAREHOUSE_INACTIVE');
        }
    }

    /** @param array<string, mixed> $details */
    private function conflict(string $code, array $details = []): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code, ...$details], 409));
    }
}
