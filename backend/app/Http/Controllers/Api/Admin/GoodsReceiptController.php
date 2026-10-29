<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(GoodsReceipt::query()->with(['purchaseOrder:id,code,supplier_id,warehouse_id', 'items.orderItem:id,product_variant_id,sku_snapshot'])
            ->when(isset($filters['purchase_order_id']), fn ($query) => $query->where('purchase_order_id', $filters['purchase_order_id']))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(GoodsReceipt $goodsReceipt, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->receiptDetail($goodsReceipt)]);
    }

    public function store(StoreGoodsReceiptRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->receive($purchaseOrder, $request->validated(), $request->user()->id)], 201);
    }
}
