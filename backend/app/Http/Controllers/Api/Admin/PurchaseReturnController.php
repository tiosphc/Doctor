<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(PurchaseReturn::query()->with(['purchaseOrder:id,code,supplier_id,warehouse_id', 'items.receiptItem.orderItem:id,product_variant_id,sku_snapshot'])
            ->when(isset($filters['purchase_order_id']), fn ($query) => $query->where('purchase_order_id', $filters['purchase_order_id']))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(PurchaseReturn $purchaseReturn, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->returnDetail($purchaseReturn)]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'distinct', 'exists:goods_receipt_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $procurement->returnGoods($purchaseOrder, $data, $request->user()->id)], 201);
    }
}
