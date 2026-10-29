<?php

namespace App\Services;

use App\Models\DealerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealerNetRevenueService
{
    /** @return array{settled: string, refunded: string, returned: string, net: string, currency: string} */
    public function forAccount(DealerAccount $account, ?CarbonImmutable $periodStart = null, ?CarbonImmutable $periodEnd = null): array
    {
        $payments = DB::table('payment_allocations as allocation')
            ->join('payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->join('sales_orders as sales_order', 'sales_order.id', '=', 'allocation.sales_order_id')
            ->where('sales_order.sales_channel', 'dealer')
            ->where('sales_order.dealer_account_id', $account->id)
            ->whereIn('sales_order.order_status', ['confirmed', 'processing', 'completed'])
            ->where('payment.status', 'settled')
            ->where('payment.payment_context', 'dealer')
            ->where('payment.dealer_account_id', $account->id)
            ->when($periodStart !== null && $periodEnd !== null, fn ($query) => $query
                ->whereBetween('payment.settled_at', [$periodStart, $periodEnd]));

        $refunds = DB::table('refund_allocations as allocation')
            ->join('refunds as refund', 'refund.id', '=', 'allocation.refund_id')
            ->join('payment_allocations as payment_allocation', 'payment_allocation.id', '=', 'allocation.payment_allocation_id')
            ->join('payments as payment', 'payment.id', '=', 'payment_allocation.payment_id')
            ->join('sales_orders as sales_order', 'sales_order.id', '=', 'payment_allocation.sales_order_id')
            ->where('sales_order.sales_channel', 'dealer')
            ->where('sales_order.dealer_account_id', $account->id)
            ->whereIn('sales_order.order_status', ['confirmed', 'processing', 'completed'])
            ->whereColumn('refund.sales_order_id', 'sales_order.id')
            ->where('refund.status', 'completed')
            ->where('payment.status', 'settled')
            ->where('payment.payment_context', 'dealer')
            ->where('payment.dealer_account_id', $account->id)
            ->when($periodStart !== null && $periodEnd !== null, fn ($query) => $query
                ->whereBetween('refund.completed_at', [$periodStart, $periodEnd]));

        $returns = DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->join('sales_orders as sales_order', 'sales_order.id', '=', 'sales_return.sales_order_id')
            ->where('sales_order.sales_channel', 'dealer')
            ->where('sales_order.dealer_account_id', $account->id)
            ->whereIn('sales_order.order_status', ['confirmed', 'processing', 'completed'])
            ->where('sales_return.status', 'completed')
            ->when($periodStart !== null && $periodEnd !== null, fn ($query) => $query
                ->whereBetween('sales_return.completed_at', [$periodStart, $periodEnd]));

        $returnRefunds = DB::table('refund_allocations as allocation')
            ->join('refunds as refund', 'refund.id', '=', 'allocation.refund_id')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'refund.sales_return_id')
            ->join('sales_orders as sales_order', 'sales_order.id', '=', 'sales_return.sales_order_id')
            ->where('sales_order.sales_channel', 'dealer')
            ->where('sales_order.dealer_account_id', $account->id)
            ->whereIn('sales_order.order_status', ['confirmed', 'processing', 'completed'])
            ->where('sales_return.status', 'completed')
            ->where('refund.status', 'completed')
            ->when($periodStart !== null && $periodEnd !== null, fn ($query) => $query
                ->whereBetween('sales_return.completed_at', [$periodStart, $periodEnd])
                ->where('refund.completed_at', '<=', $periodEnd));

        if ((clone $payments)->where(fn ($query) => $query->where('payment.currency', '!=', 'VND')
            ->orWhere('sales_order.currency', '!=', 'VND'))->exists()
            || (clone $refunds)->where(fn ($query) => $query->where('refund.currency', '!=', 'VND')
                ->orWhere('payment.currency', '!=', 'VND')->orWhere('sales_order.currency', '!=', 'VND'))->exists()) {
            $this->conflict('AUTO_TIER_LEDGER_INVALID');
        }

        $settled = bcadd((string) (clone $payments)->sum('allocation.allocated_amount'), '0', 2);
        $refunded = bcadd((string) (clone $refunds)->sum('allocation.amount'), '0', 2);
        $returnValue = bcadd((string) (clone $returns)->sum(DB::raw('COALESCE(item.return_value_snapshot, ROUND(item.quantity * item.unit_value_snapshot, 2))')), '0', 2);
        $linkedRefundValue = bcadd((string) $returnRefunds->sum('allocation.amount'), '0', 2);
        $returned = bccomp($returnValue, $linkedRefundValue, 2) > 0
            ? bcsub($returnValue, $linkedRefundValue, 2) : '0.00';
        $net = bcsub(bcsub($settled, $refunded, 2), $returned, 2);
        if ($periodStart === null && bccomp($net, '0', 2) < 0) {
            $this->conflict('AUTO_TIER_LEDGER_INVALID');
        }

        return ['settled' => $settled, 'refunded' => $refunded, 'returned' => $returned, 'net' => $net, 'currency' => 'VND'];
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
