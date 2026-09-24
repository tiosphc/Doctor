<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public const OPENING = 'OPENING_BALANCE';

    public const RECEIPT = 'GOODS_RECEIPT';

    public const ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function opening(array $data, ?int $actorId): StockMovement
    {
        return $this->operate(self::OPENING, $data, $actorId);
    }

    /** @param array<string, mixed> $data */
    public function receive(array $data, ?int $actorId): StockMovement
    {
        return $this->operate(self::RECEIPT, $data, $actorId);
    }

    /** @param array<string, mixed> $data */
    public function adjust(array $data, ?int $actorId): StockMovement
    {
        $type = str_starts_with((string) $data['quantity'], '-') ? self::ADJUSTMENT_OUT : self::ADJUSTMENT_IN;

        return $this->operate($type, $data, $actorId);
    }

    /** @param array<string, mixed> $data */
    private function operate(string $type, array $data, ?int $actorId): StockMovement
    {
        if (in_array($type, [self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT], true)
            && (blank($data['reason_code'] ?? null) || blank($data['reason_detail'] ?? null))) {
            throw ValidationException::withMessages(['reason_detail' => 'A stock adjustment requires a reason code and detail.']);
        }
        if ($type === self::OPENING && blank($data['reason_detail'] ?? null)) {
            throw ValidationException::withMessages(['reason_detail' => 'Opening stock requires a source note.']);
        }
        $quantity = (string) $data['quantity'];
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/', $quantity) !== 1
            || bccomp($quantity, '0', 3) === 0
            || ($type !== self::ADJUSTMENT_OUT && bccomp($quantity, '0', 3) < 0)
            || ($type === self::ADJUSTMENT_OUT && bccomp($quantity, '0', 3) > 0)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a non-zero signed decimal with at most three places.']);
        }
        $quantity = bcadd($quantity, '0', 3);
        $key = (string) $data['operation_key'];
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $key) !== 1) {
            throw ValidationException::withMessages(['operation_key' => 'Operation key must be a UUID.']);
        }
        $existing = StockMovement::query()->where('operation_key', $key)->first();
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $type, $data, $quantity, $actorId);
        }

        try {
            return DB::transaction(function () use ($type, $data, $actorId, $quantity, $key): StockMovement {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($data['warehouse_id']);
                $existing = StockMovement::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $type, $data, $quantity, $actorId);
                }
                if ($warehouse->status !== 'active') {
                    $this->conflict('WAREHOUSE_INACTIVE');
                }

                $variant = ProductVariant::query()->with(['product', 'unit'])->lockForUpdate()->findOrFail($data['product_variant_id']);
                if ($variant->status !== 'active' || $variant->product->status === 'inactive' || ! $variant->track_inventory) {
                    $this->conflict('SKU_NOT_INVENTORY_CAPABLE');
                }
                $requestedPlaces = strlen(explode('.', ltrim((string) $data['quantity'], '-'))[1] ?? '');
                if ($requestedPlaces > $variant->unit->decimal_precision) {
                    throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the SKU unit precision.']);
                }

                DB::table('inventory_balances')->insertOrIgnore([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id,
                    'on_hand_quantity' => '0.000',
                    'reserved_quantity' => '0.000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $balance = DB::table('inventory_balances')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()->first();
                $before = (string) $balance->on_hand_quantity;
                $reserved = (string) $balance->reserved_quantity;
                if ($type === self::OPENING && (bccomp($before, '0', 3) !== 0
                    || DB::table('stock_movements')->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->exists())) {
                    $this->conflict('OPENING_STOCK_ALREADY_RECORDED');
                }
                $after = bcadd($before, $quantity, 3);
                if (bccomp($after, $reserved, 3) < 0 || bccomp($after, '0', 3) < 0) {
                    $this->conflict('INSUFFICIENT_AVAILABLE_STOCK');
                }

                $movementId = DB::table('stock_movements')->insertGetId([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id,
                    'movement_type' => $type,
                    'quantity' => $quantity,
                    'before_on_hand_quantity' => $before,
                    'after_on_hand_quantity' => $after,
                    'reference_type' => $data['reference_type'] ?? null,
                    'reference_id' => $data['reference_id'] ?? null,
                    'operation_key' => $key,
                    'reason_code' => $data['reason_code'] ?? null,
                    'reason_detail' => $data['reason_detail'] ?? null,
                    'actor_user_id' => $actorId,
                    'source' => 'admin',
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('inventory_balances')->where('id', $balance->id)->update([
                    'on_hand_quantity' => $after,
                    'updated_at' => now(),
                ]);
                $movement = StockMovement::query()->findOrFail($movementId);
                $this->auditLogger->log(
                    $type,
                    AuditLogger::MODULE_INVENTORY,
                    $movement,
                    'Inventory stock operation',
                    ['on_hand_quantity' => $before],
                    ['on_hand_quantity' => $after],
                    ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
                        'movement_id' => $movementId, 'quantity' => $quantity,
                        'reason_code' => $data['reason_code'] ?? null,
                        'reason_detail' => $data['reason_detail'] ?? null,
                        'operation_key' => $key],
                );

                return $movement;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = StockMovement::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $type, $data, $quantity, $actorId);
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function replayOrConflict(StockMovement $movement, string $type, array $data, string $quantity, ?int $actorId): StockMovement
    {
        if ($movement->movement_type !== $type
            || $movement->warehouse_id !== (int) $data['warehouse_id']
            || $movement->product_variant_id !== (int) $data['product_variant_id']
            || bccomp($movement->quantity, $quantity, 3) !== 0
            || $movement->actor_user_id !== $actorId
            || $movement->reason_code !== ($data['reason_code'] ?? null)
            || $movement->reason_detail !== ($data['reason_detail'] ?? null)
            || $movement->reference_type !== ($data['reference_type'] ?? null)
            || $movement->reference_id !== ($data['reference_id'] ?? null)) {
            $this->conflict('OPERATION_KEY_CONFLICT');
        }

        return $movement;
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
