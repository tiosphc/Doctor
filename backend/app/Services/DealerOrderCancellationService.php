<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealerOrderCancellationService
{
    public function __construct(
        private readonly OrderPaymentSummaryService $summaries,
        private readonly RefundService $refunds,
        private readonly SalesOrderService $orders,
    ) {}

    public function cancel(DealerAccount $dealer, int $orderId, string $operationKey, User $actor): SalesOrder
    {
        $reason = 'Dealer requested cancellation';

        return DB::transaction(function () use ($dealer, $orderId, $operationKey, $actor, $reason): SalesOrder {
            $order = SalesOrder::query()->whereKey($orderId)->where('sales_channel', 'dealer')
                ->where('dealer_account_id', $dealer->id)->lockForUpdate()->firstOrFail();

            if ($order->order_status === 'cancelled') {
                return $this->orders->cancel($order, $operationKey, $reason, $actor->id);
            }

            if ($order->order_status !== 'confirmed' || $order->fulfillment_status !== 'reserved') {
                $this->conflict('ORDER_INVALID_STATE');
            }

            $payment = $this->summaries->summary($order);
            if ($this->summaries->hasUnsupportedLegacyMarker($order, $payment['paid_amount'])) {
                $this->conflict('PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW');
            }

            if (bccomp($payment['refundable_amount'], '0', 2) > 0) {
                $hasOtherPaymentMethod = $order->payment_method !== 'dealer_wallet'
                    || $order->paymentAllocations()->whereHas('payment', fn (Builder $query): Builder => $query
                        ->where('status', 'settled')->where(fn (Builder $query): Builder => $query
                        ->where('payment_method', '!=', 'dealer_wallet')
                        ->orWhere('payment_context', '!=', 'dealer')
                        ->orWhere('dealer_account_id', '!=', $dealer->id)
                        ->orWhereNull('dealer_account_id')
                        ->orWhere('currency', '!=', $order->currency)))->exists();
                if ($hasOtherPaymentMethod) {
                    $this->conflict('DEALER_WALLET_REFUND_REQUIRED');
                }

                $this->refunds->complete($order, [
                    'operation_key' => $operationKey,
                    'amount' => $payment['refundable_amount'],
                    'refund_method' => 'dealer_wallet',
                    'reason' => 'order_cancel',
                ], $actor->id);
            }

            return $this->orders->cancel($order, $operationKey, $reason, $actor->id);
        }, 3);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
