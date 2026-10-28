<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPurchasePaymentRequest;
use App\Http\Requests\Admin\SavePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:draft,ordered,partially_received,received,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PurchaseOrder::query()->with(['supplier:id,code,name', 'warehouse:id,code,name'])->withCount('items');
        if (isset($filters['search'])) {
            $query->where('code', 'like', '%'.$filters['search'].'%');
        }
        if (isset($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 20));
    }

    public function store(SavePurchaseOrderRequest $request, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->createOrder($request->validated(), $request->user()->id)], 201);
    }

    public function show(PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->orderDetail($purchaseOrder)]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->updateOrder($purchaseOrder, $request->validated())]);
    }

    public function issue(PurchaseOrder $purchaseOrder, Request $request, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->issueOrder($purchaseOrder, $request->user()->id)]);
    }

    public function cancel(PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->cancelOrder($purchaseOrder)]);
    }

    public function recordPayment(RecordPurchasePaymentRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->recordPayment($purchaseOrder, $request->validated(), $request->user()->id)]);
    }
}
