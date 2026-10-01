<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteSalesReturnRequest;
use App\Http\Requests\Admin\ProcessSalesReturnRequest;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReturnController extends Controller
{
    public function pending(): JsonResponse
    {
        $returns = SalesReturn::query()->where('status', 'requested')
            ->whereIn('request_source', ['dealer', 'retail'])
            ->with(['salesOrder:id,order_code,sales_channel,recipient_name', 'requestedBy:id,name'])
            ->oldest('id')->limit(50)->get();

        return response()->json(['data' => $returns->map(fn (SalesReturn $entry): array => [
            'id' => $entry->id, 'return_code' => $entry->return_code,
            'sales_order_id' => $entry->sales_order_id,
            'order_code' => $entry->salesOrder?->order_code,
            'sales_channel' => $entry->salesOrder?->sales_channel,
            'recipient_name' => $entry->salesOrder?->recipient_name,
            'requested_by' => $entry->requestedBy?->name,
            'requested_at' => $entry->created_at,
        ])]);
    }

    public function index(SalesOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->salesReturns()->with(['items.salesOrderItem', 'requestedBy', 'approvedBy', 'rejectedBy', 'receivedBy', 'processedBy', 'refunds'])
            ->latest('id')->get()->map(fn (SalesReturn $salesReturn): array => $this->item($salesReturn))]);
    }

    public function returnable(SalesOrder $order, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $service->returnable($order)]);
    }

    public function store(CompleteSalesReturnRequest $request, SalesOrder $order, SalesReturnService $service): JsonResponse
    {
        $salesReturn = $service->complete($order, $request->validated(), $request->user()->id);

        return response()->json(['data' => $this->item($salesReturn)], 201);
    }

    public function show(SalesReturn $salesReturn): JsonResponse
    {
        return response()->json(['data' => $this->item($salesReturn->load(['items.salesOrderItem', 'receivedBy', 'processedBy', 'refunds']))]);
    }

    public function process(ProcessSalesReturnRequest $request, SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $this->item($service->process($salesReturn, $request->validated(), $request->user()->id))]);
    }

    public function approve(Request $request, SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $this->item($service->approve($salesReturn, $request->user()->id))]);
    }

    public function reject(Request $request, SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->item($service->reject($salesReturn, $data['reason'], $request->user()->id))]);
    }

    public function receive(Request $request, SalesReturn $salesReturn, SalesReturnService $service): JsonResponse
    {
        return response()->json(['data' => $this->item($service->receive($salesReturn, $request->user()->id))]);
    }

    /** @return array<string, mixed> */
    private function item(SalesReturn $salesReturn): array
    {
        $salesReturn->loadMissing(['items.salesOrderItem', 'requestedBy', 'approvedBy', 'rejectedBy', 'receivedBy', 'processedBy', 'refunds']);
        $movements = DB::table('stock_movements')
            ->where('reference_type', 'SALES_RETURN_ITEM')
            ->whereIn('reference_id', $salesReturn->items->pluck('id')->map(fn ($id): string => (string) $id))
            ->where('movement_type', 'SALES_RETURN')->get();

        return ['id' => $salesReturn->id, 'return_code' => $salesReturn->return_code,
            'sales_order_id' => $salesReturn->sales_order_id, 'warehouse_id' => $salesReturn->warehouse_id,
            'status' => $salesReturn->status, 'request_source' => $salesReturn->request_source ?? 'admin',
            'requested_by' => $salesReturn->requestedBy?->only(['id', 'name']),
            'approved_by' => $salesReturn->approvedBy?->only(['id', 'name']),
            'rejected_by' => $salesReturn->rejectedBy?->only(['id', 'name']),
            'reason' => $salesReturn->reason, 'reason_code' => $salesReturn->reason_code,
            'note' => $salesReturn->note, 'rejection_reason' => $salesReturn->rejection_reason,
            'created_at' => $salesReturn->created_at, 'completed_at' => $salesReturn->completed_at,
            'approved_at' => $salesReturn->approved_at, 'rejected_at' => $salesReturn->rejected_at,
            'received_at' => $salesReturn->received_at,
            'received_by' => $salesReturn->receivedBy?->only(['id', 'name']),
            'processed_by' => $salesReturn->status === 'completed' ? $salesReturn->processedBy?->only(['id', 'name']) : null,
            'return_value' => $salesReturn->items->sum(fn ($item): float => (float) $item->return_value_snapshot),
            'refunded_amount' => $salesReturn->refunds->where('status', 'completed')->sum(fn ($refund): float => (float) $refund->amount),
            'items' => $salesReturn->items->map(fn ($item): array => [
                'id' => $item->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'product_name' => $item->salesOrderItem?->product_name_snapshot,
                'sku' => $item->salesOrderItem?->sku_snapshot,
                'quantity' => $item->quantity, 'restock_quantity' => $item->restock_quantity,
                'non_restock_quantity' => $item->non_restock_quantity,
                'non_restock_reason_code' => $item->non_restock_reason_code,
                'non_restock_note' => $item->non_restock_note,
                'stock_movement_id' => $movements->firstWhere('reference_id', (string) $item->id)?->id,
                'unit_value' => $item->unit_value_snapshot,
                'return_value_snapshot' => $item->return_value_snapshot,
            ])];
    }
}
