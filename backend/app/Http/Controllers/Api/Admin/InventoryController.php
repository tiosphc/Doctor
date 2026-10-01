<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InventoryOperationRequest;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Services\InventoryReconciliationService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = InventoryBalance::query()
            ->select('inventory_balances.*')
            ->addSelect(['last_movement_at' => StockMovement::query()
                ->select('occurred_at')
                ->whereColumn('warehouse_id', 'inventory_balances.warehouse_id')
                ->whereColumn('product_variant_id', 'inventory_balances.product_variant_id')
                ->latest('occurred_at')->latest('id')->limit(1)])
            ->with(['warehouse:id,code,name,status', 'variant:id,product_id,unit_id,sku,variant_name,status,track_inventory',
                'variant.product:id,product_code,name,status,default_low_stock_threshold', 'variant.unit:id,name,symbol,decimal_precision']);
        foreach (['warehouse_id', 'product_variant_id'] as $column) {
            if (isset($data[$column])) {
                $query->where('inventory_balances.'.$column, $data[$column]);
            }
        }
        if (isset($data['product_id'])) {
            $query->whereHas('variant', fn ($builder) => $builder->where('product_id', $data['product_id']));
        }
        if (isset($data['status'])) {
            $query->whereHas('variant', fn ($builder) => $builder->where('status', $data['status']));
        }
        if (isset($data['search'])) {
            $query->whereHas('variant', fn ($builder) => $builder->where('sku', 'like', '%'.$data['search'].'%')
                ->orWhere('variant_name', 'like', '%'.$data['search'].'%')
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', '%'.$data['search'].'%')
                    ->orWhere('product_code', 'like', '%'.$data['search'].'%')));
        }
        if ($data['low_stock'] ?? false) {
            $query->whereHas('variant.product', fn ($builder) => $builder
                ->whereNotNull('default_low_stock_threshold')
                ->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= products.default_low_stock_threshold'));
        }

        return response()->json($query->orderBy('inventory_balances.warehouse_id')
            ->orderBy('inventory_balances.product_variant_id')
            ->paginate($data['per_page'] ?? 20)
            ->through(fn (InventoryBalance $balance): array => $this->balanceData($balance)));
    }

    public function opening(InventoryOperationRequest $request, InventoryService $inventory): JsonResponse
    {
        return $this->operationResponse($inventory->opening($request->validated(), $request->user()->id));
    }

    public function receipt(InventoryOperationRequest $request, InventoryService $inventory): JsonResponse
    {
        return $this->operationResponse($inventory->receive($request->validated(), $request->user()->id));
    }

    public function adjustment(InventoryOperationRequest $request, InventoryService $inventory): JsonResponse
    {
        return $this->operationResponse($inventory->adjust($request->validated(), $request->user()->id));
    }

    public function reconciliation(Request $request, InventoryReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ]);

        return response()->json(['data' => $reconciliation->report($data['warehouse_id'] ?? null, $data['product_variant_id'] ?? null)]);
    }

    private function operationResponse(StockMovement $movement): JsonResponse
    {
        return response()->json(['data' => $movement->load(['warehouse:id,code,name', 'variant:id,sku,variant_name', 'actor:id,name'])]);
    }

    /** @return array<string, mixed> */
    private function balanceData(InventoryBalance $balance): array
    {
        $variant = $balance->variant;
        $threshold = $variant->product->default_low_stock_threshold;

        return [
            'id' => $balance->id,
            'warehouse_id' => $balance->warehouse_id,
            'product_variant_id' => $balance->product_variant_id,
            'warehouse' => $balance->warehouse->only(['id', 'code', 'name', 'status']),
            'product' => $variant->product->only(['id', 'product_code', 'name', 'status']),
            'variant' => $variant->only(['id', 'sku', 'variant_name', 'status', 'track_inventory']),
            'unit' => $variant->unit->only(['id', 'name', 'symbol', 'decimal_precision']),
            'on_hand_quantity' => $balance->on_hand_quantity,
            'reserved_quantity' => $balance->reserved_quantity,
            'available_quantity' => $balance->available_quantity,
            'low_stock' => $threshold !== null && bccomp($balance->available_quantity, $threshold, 3) <= 0,
            'last_movement_at' => $balance->last_movement_at,
        ];
    }
}
