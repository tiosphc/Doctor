<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\OrderPaymentSummaryService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function index(SalesOrder $order, OrderPaymentSummaryService $summaries): JsonResponse
    {
        $payments = Payment::query()->with(['allocations.salesOrder:id,order_code', 'recorder:id,name'])
            ->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))
            ->orderByDesc('id')->get();

        return response()->json(['data' => ['summary' => $summaries->summary($order),
            'payments' => $payments->map(fn (Payment $payment): array => $this->item($payment, $order->id))]]);
    }

    public function store(RecordPaymentRequest $request, SalesOrder $order, PaymentService $service,
        OrderPaymentSummaryService $summaries): JsonResponse
    {
        $payment = $service->recordSettledPayment($order, $request->validated(), $request->user()->id);

        return response()->json(['data' => ['payment' => $this->item($payment, $order->id),
            'summary' => $summaries->summary($order->refresh())]], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load('allocations.salesOrder:id,order_code', 'recorder:id,name');

        return response()->json(['data' => $this->item($payment)]);
    }

    /** @return array<string, mixed> */
    private function item(Payment $payment, ?int $orderId = null): array
    {
        return ['id' => $payment->id, 'payment_code' => $payment->payment_code,
            'payment_context' => $payment->payment_context, 'dealer_account_id' => $payment->dealer_account_id,
            'currency' => $payment->currency, 'amount' => $payment->amount,
            'payment_method' => $payment->payment_method, 'status' => $payment->status,
            'external_reference' => $payment->external_reference, 'note' => $payment->note,
            'recorded_by' => $payment->recorder?->only(['id', 'name']),
            'settled_at' => $payment->settled_at, 'created_at' => $payment->created_at,
            'allocations' => $payment->allocations->map(fn ($allocation): array => [
                'sales_order_id' => $allocation->sales_order_id,
                'order_code' => $allocation->salesOrder?->order_code,
                'allocated_amount' => $allocation->allocated_amount,
            ]), 'sales_order_id' => $orderId];
    }
}
