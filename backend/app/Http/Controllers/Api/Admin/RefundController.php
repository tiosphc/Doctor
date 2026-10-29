<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteRefundRequest;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Services\OrderPaymentSummaryService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;

class RefundController extends Controller
{
    public function index(SalesOrder $order, OrderPaymentSummaryService $summaries): JsonResponse
    {
        return response()->json(['data' => ['summary' => $summaries->summary($order),
            'refunds' => $order->refunds()->with('allocations.paymentAllocation.payment')->latest('id')->get()
                ->map(fn (Refund $refund): array => $this->item($refund))]]);
    }

    public function store(CompleteRefundRequest $request, SalesOrder $order, RefundService $service,
        OrderPaymentSummaryService $summaries): JsonResponse
    {
        $refund = $service->complete($order, $request->validated(), $request->user()->id);

        return response()->json(['data' => ['refund' => $this->item($refund),
            'summary' => $summaries->summary($order->refresh())]], 201);
    }

    public function show(Refund $refund): JsonResponse
    {
        return response()->json(['data' => $this->item($refund->load('allocations.paymentAllocation.payment'))]);
    }

    /** @return array<string, mixed> */
    private function item(Refund $refund): array
    {
        return ['id' => $refund->id, 'refund_code' => $refund->refund_code,
            'sales_order_id' => $refund->sales_order_id, 'return_id' => $refund->sales_return_id,
            'currency' => $refund->currency, 'amount' => $refund->amount,
            'refund_method' => $refund->refund_method, 'status' => $refund->status,
            'reason' => $refund->reason_code, 'note' => $refund->note,
            'external_reference' => $refund->external_reference,
            'completed_at' => $refund->completed_at,
            'allocations' => $refund->allocations->map(fn ($allocation): array => [
                'payment_allocation_id' => $allocation->payment_allocation_id,
                'payment_code' => $allocation->paymentAllocation?->payment?->payment_code,
                'amount' => $allocation->amount,
            ])];
    }
}
