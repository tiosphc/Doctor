<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'movement_type' => ['nullable', 'in:OPENING_BALANCE,GOODS_RECEIPT,ADJUSTMENT_IN,ADJUSTMENT_OUT,PURCHASE_RETURN'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'reference' => ['nullable', 'string', 'max:100'],
            'actor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = StockMovement::query()->with(['warehouse:id,code,name', 'variant:id,product_id,sku,variant_name',
            'variant.product:id,product_code,name', 'actor:id,name']);
        foreach (['warehouse_id', 'product_variant_id', 'movement_type', 'actor_user_id'] as $column) {
            if (isset($data[$column])) {
                $query->where($column, $data[$column]);
            }
        }
        if (isset($data['from'])) {
            $query->whereDate('occurred_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('occurred_at', '<=', $data['to']);
        }
        if (isset($data['reference'])) {
            $query->where('reference_id', 'like', '%'.$data['reference'].'%');
        }

        return response()->json($query->latest('occurred_at')->latest('id')->paginate($data['per_page'] ?? 20));
    }

    public function show(StockMovement $movement): JsonResponse
    {
        return response()->json(['data' => $movement->load(['warehouse:id,code,name', 'variant:id,product_id,sku,variant_name',
            'variant.product:id,product_code,name', 'actor:id,name'])]);
    }
}
