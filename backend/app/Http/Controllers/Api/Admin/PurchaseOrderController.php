<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
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

    public function store(Request $request, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->createOrder($this->orderData($request), $request->user()->id)], 201);
    }

    public function show(PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->orderDetail($purchaseOrder)]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->updateOrder($purchaseOrder, $this->orderData($request, true))]);
    }

    public function issue(PurchaseOrder $purchaseOrder, Request $request, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->issueOrder($purchaseOrder, $request->user()->id)]);
    }

    public function cancel(PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        return response()->json(['data' => $procurement->cancelOrder($purchaseOrder)]);
    }

    public function recordPayment(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'amount' => ['required', 'regex:/^[1-9][0-9]{0,14}(?:\.[0-9]{1,2})?$/'],
            'payment_method' => ['required', 'string', 'max:50'],
            'paid_at' => ['required', 'date'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $procurement->recordPayment($purchaseOrder, $data, $request->user()->id)]);
    }

    /** @return array<string, mixed> */
    private function orderData(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return $request->validate([
            'supplier_id' => [$required, 'integer', 'exists:suppliers,id'],
            'warehouse_id' => [$required, 'integer', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_order_reference' => ['nullable', 'string', 'max:100'],
            'items' => [$required, 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?$/'],
        ]);
    }
}
