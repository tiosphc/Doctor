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
        $required = [];
        foreach ($items as $item) {
            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($item->product_variant_id);
            $sellable = $order->sales_channel === 'dealer' ? $variant->sellable_dealer : $variant->sellable_retail;
            if ($variant->status !== 'active' || $variant->product->status !== 'active'
                || ($item->is_gift ? ! $order->permitsPromotionGift($variant, $item->source_promotion_id)
                    : (! $sellable || $variant->product->gift_only))
                || ! $variant->track_inventory) {
                $this->conflict('SKU_UNAVAILABLE');
            }
            $required[$variant->id] = bcadd($required[$variant->id] ?? '0.000', $item->quantity, 3);
            if (array_key_exists($variant->id, $balances)) {
                continue;
            }
            $balances[$variant->id] = DB::table('inventory_balances')->where('warehouse_id', $order->warehouse_id)
                ->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
        }
        foreach ($required as $variantId => $quantity) {
            $balance = $balances[$variantId];
            $available = $balance === null ? '0.000' : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            if (bccomp($available, $quantity, 3) < 0) {
                $this->conflict('INSUFFICIENT_STOCK', [
                    'sku' => $items->firstWhere('product_variant_id', $variantId)?->sku_snapshot, 'requested' => $quantity,
                    'available' => $available, 'warehouse_id' => $order->warehouse_id,
                ]);
            }
        }
        foreach ($required as $variantId => $quantity) {
            $balance = $balances[$variantId];
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'reserved_quantity' => bcadd((string) $balance->reserved_quantity, $quantity, 3),
                'updated_at' => now(),
            ]);
        }
        foreach ($items as $item) {
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

    public function relocate(SalesOrder $order, Warehouse $destination): void
    {
        Warehouse::query()->whereIn('id', [$order->warehouse_id, $destination->id])
            ->orderBy('id')->lockForUpdate()->get();
        if ($destination->status !== 'active') {
            $this->conflict('WAREHOUSE_INACTIVE');
        }
        $reservations = InventoryReservation::query()->where('sales_order_id', $order->id)
            ->orderBy('product_variant_id')->lockForUpdate()->get();
        if ($reservations->count() !== $order->items->count()) {
            $this->conflict('ORDER_INVALID_STATE');
        }
        $required = [];
        foreach ($reservations as $reservation) {
            $remaining = $this->remaining($reservation);
            if (bccomp($remaining, '0', 3) <= 0 || bccomp($reservation->consumed_quantity, '0', 3) > 0) {
                $this->conflict('ORDER_INVALID_STATE');
            }
            $required[$reservation->product_variant_id] = bcadd($required[$reservation->product_variant_id] ?? '0.000', $remaining, 3);
        }
        foreach ($required as $variantId => $quantity) {
            $balances = DB::table('inventory_balances')
                ->whereIn('warehouse_id', [$order->warehouse_id, $destination->id])
                ->where('product_variant_id', $variantId)
                ->orderBy('warehouse_id')->lockForUpdate()->get()->keyBy('warehouse_id');
            $old = $balances->get($order->warehouse_id);
            $new = $balances->get($destination->id);
            $available = $new === null ? '0.000' : bcsub((string) $new->on_hand_quantity, (string) $new->reserved_quantity, 3);
            if ($old === null || $new === null || bccomp($available, $quantity, 3) < 0) {
                $this->conflict('INSUFFICIENT_STOCK', [
                    'sku' => $order->items->firstWhere('product_variant_id', $variantId)?->sku_snapshot,
                    'requested' => $quantity, 'available' => $available, 'warehouse_id' => $destination->id,
                ]);
            }
            DB::table('inventory_balances')->where('id', $old->id)->update([
                'reserved_quantity' => bcsub((string) $old->reserved_quantity, $quantity, 3), 'updated_at' => now(),
            ]);
            DB::table('inventory_balances')->where('id', $new->id)->update([
                'reserved_quantity' => bcadd((string) $new->reserved_quantity, $quantity, 3), 'updated_at' => now(),
            ]);
        }
        foreach ($reservations as $reservation) {
            $reservation->update(['warehouse_id' => $destination->id]);
        }
    }

    public function assertAvailable(SalesOrder $order, Warehouse $warehouse): void
    {
        Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
        if ($warehouse->status !== 'active') {
            $this->conflict('WAREHOUSE_INACTIVE');
        }
        $required = [];
        foreach ($order->items->sortBy('product_variant_id') as $item) {
            $required[$item->product_variant_id] = bcadd($required[$item->product_variant_id] ?? '0.000', $item->quantity, 3);
        }
        foreach ($required as $variantId => $quantity) {
            $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variantId)->lockForUpdate()->first();
            $available = $balance === null ? '0.000'
                : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            if (bccomp($available, $quantity, 3) < 0) {
                $this->conflict('INSUFFICIENT_STOCK', ['sku' => $order->items->firstWhere('product_variant_id', $variantId)?->sku_snapshot,
                    'requested' => $quantity, 'available' => $available, 'warehouse_id' => $warehouse->id]);
            }
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
            $unitCost = $balance->average_unit_cost === null ? null : (string) $balance->average_unit_cost;
            $costAmount = $unitCost === null ? null
                : bcmul($quantity, $unitCost, 2);
            $movementId = DB::table('stock_movements')->insertGetId([
                'warehouse_id' => $order->warehouse_id, 'product_variant_id' => $item->product_variant_id,
                'movement_type' => 'SALES_ORDER_SHIPMENT', 'quantity' => bcsub('0', $quantity, 3),
                'before_on_hand_quantity' => $balance->on_hand_quantity, 'after_on_hand_quantity' => $after,
                'unit_cost' => $unitCost, 'cost_amount' => $costAmount,
                'reference_type' => 'SALES_ORDER_ITEM', 'reference_id' => (string) $item->id,
                'operation_key' => (string) Str::uuid(), 'actor_user_id' => $actorId,
                'source' => 'admin', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'on_hand_quantity' => $after,
                'average_unit_cost' => bccomp($after, '0', 3) === 0 ? null : $unitCost,
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
