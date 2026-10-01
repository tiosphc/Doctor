<?php

namespace App\Services;

use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;

class OrderPaymentSummaryService
{
    public function withSettledSum(Builder $query): Builder
    {
        return $query->withSum(['paymentAllocations as settled_paid_amount' => fn (Builder $builder): Builder => $builder->whereHas('payment',
            fn (Builder $payment): Builder => $payment->where('status', 'settled'))], 'allocated_amount')
            ->withSum(['refundAllocations as completed_refunded_amount' => fn (Builder $builder): Builder => $builder->whereHas('refund',
                fn (Builder $refund): Builder => $refund->where('status', 'completed'))], 'amount');
    }

    public function paid(SalesOrder $order): string
    {
        if ($order->getAttribute('settled_paid_amount') !== null) {
            return bcadd((string) $order->getAttribute('settled_paid_amount'), '0', 2);
        }

        return bcadd((string) $order->paymentAllocations()->whereHas('payment',
            fn (Builder $payment): Builder => $payment->where('status', 'settled'))->sum('allocated_amount'), '0', 2);
    }

    public function refunded(SalesOrder $order): string
    {
        if ($order->getAttribute('completed_refunded_amount') !== null) {
            return bcadd((string) $order->getAttribute('completed_refunded_amount'), '0', 2);
        }

        return bcadd((string) $order->refundAllocations()->whereHas('refund',
            fn (Builder $refund): Builder => $refund->where('status', 'completed'))->sum('amount'), '0', 2);
    }

    /** @return array<string, string> */
    public function summary(SalesOrder $order): array
    {
        $paid = $this->paid($order);
        $refunded = $this->refunded($order);
        $refundable = bcsub($paid, $refunded, 2);
        $outstanding = bcsub((string) $order->grand_total, $paid, 2);

        return ['paid_amount' => $paid, 'outstanding_amount' => $outstanding,
            'payment_status' => $this->status((string) $order->grand_total, $paid),
            'refunded_amount' => $refunded, 'refundable_amount' => $refundable,
            'net_settled_amount' => $refundable,
            'refund_status' => $this->refundStatus($refunded, $refundable)];
    }

    public function refundStatus(string $refunded, string $refundable): string
    {
        if (bccomp($refunded, '0', 2) === 0) {
            return 'none';
        }

        return bccomp($refundable, '0', 2) === 0 ? 'fully_refunded' : 'partially_refunded';
    }

    public function status(string $total, string $paid): string
    {
        if (bccomp($paid, '0', 2) === 0) {
            return 'unpaid';
        }

        return bccomp($paid, $total, 2) >= 0 ? 'paid' : 'partially_paid';
    }

    public function hasUnsupportedLegacyMarker(SalesOrder $order, string $paid): bool
    {
        if ($order->payment_status === 'paid') {
            return bccomp($paid, '0', 2) === 0
                || bccomp($paid, (string) $order->grand_total, 2) < 0;
        }

        return $order->payment_status === 'partially_paid' && bccomp($paid, '0', 2) === 0;
    }

    public function attach(SalesOrder $order): SalesOrder
    {
        $summary = $this->summary($order);
        $order->setAttribute('paid_amount', $summary['paid_amount']);
        $order->setAttribute('outstanding_amount', $summary['outstanding_amount']);
        $order->setAttribute('refunded_amount', $summary['refunded_amount']);
        $order->setAttribute('refundable_amount', $summary['refundable_amount']);
        $order->setAttribute('net_settled_amount', $summary['net_settled_amount']);

        return $order;
    }
}
