<?php

namespace App\Console\Commands;

use App\Models\Refund;
use App\Models\SalesOrder;
use App\Services\OrderPaymentSummaryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('refunds:reconcile-orders {--dry-run} {--apply}')]
#[Description('Audit refund lineage, limits and derived order refund status')]
class ReconcileRefunds extends Command
{
    public function handle(OrderPaymentSummaryService $summaries): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply.');

            return self::FAILURE;
        }
        $anomalies = 0;
        $repaired = 0;
        foreach (Refund::query()->with('allocations.paymentAllocation.payment', 'salesReturn.items')->cursor() as $refund) {
            $allocated = '0.00';
            foreach ($refund->allocations as $allocation) {
                $allocated = bcadd($allocated, $allocation->amount, 2);
                if ($allocation->paymentAllocation?->sales_order_id !== $refund->sales_order_id
                    || $allocation->paymentAllocation?->payment?->status !== 'settled') {
                    $anomalies++;
                    $this->warn("Refund {$refund->refund_code}: invalid source allocation.");
                }
            }
            if (bccomp($allocated, $refund->amount, 2) !== 0
                || $refund->currency !== SalesOrder::findOrFail($refund->sales_order_id)->currency) {
                $anomalies++;
                $this->warn("Refund {$refund->refund_code}: amount or currency mismatch.");
            }
            if ($refund->salesReturn !== null) {
                $value = '0.00';
                foreach ($refund->salesReturn->items as $item) {
                    $value = bcadd($value, $item->return_value_snapshot
                        ?? bcadd(bcmul($item->quantity, $item->unit_value_snapshot, 5), '0.005', 2), 2);
                }
                $used = bcadd((string) $refund->salesReturn->refunds()->where('status', 'completed')->sum('amount'), '0', 2);
                if ($refund->salesReturn->sales_order_id !== $refund->sales_order_id
                    || bccomp($used, $value, 2) > 0) {
                    $anomalies++;
                    $this->warn("Refund {$refund->refund_code}: linked return value exceeded.");
                }
            }
        }
        foreach (DB::table('payment_allocations')->get() as $allocation) {
            $used = DB::table('refund_allocations as ra')->join('refunds as r', 'r.id', '=', 'ra.refund_id')
                ->where('ra.payment_allocation_id', $allocation->id)->where('r.status', 'completed')->sum('ra.amount');
            if (bccomp((string) $used, (string) $allocation->allocated_amount, 2) > 0) {
                $anomalies++;
                $this->warn("Payment allocation {$allocation->id}: over-refunded.");
            }
        }
        $summaries->withSettledSum(SalesOrder::query())->orderBy('id')->chunkById(200,
            function ($orders) use ($summaries, &$anomalies, &$repaired): void {
                foreach ($orders as $order) {
                    $summary = $summaries->summary($order);
                    if (bccomp($summary['refundable_amount'], '0', 2) < 0) {
                        $anomalies++;
                        $this->warn("Order {$order->order_code}: negative refundable amount.");
                    }
                    if ($order->refund_status === $summary['refund_status']) {
                        continue;
                    }
                    $this->warn("Order {$order->order_code}: cached refund status differs.");
                    if (! $this->option('apply') || $anomalies > 0) {
                        continue;
                    }
                    DB::transaction(function () use ($order, $summaries, &$repaired): void {
                        $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
                        $locked->forceFill(['refund_status' => $summaries->summary($locked)['refund_status']])->save();
                        $repaired++;
                    });
                }
            });
        $this->line("anomalies: {$anomalies}");
        $this->line("status_repaired: {$repaired}");
        $this->info($this->option('apply') ? 'Safe derived status apply completed.' : 'Dry run: no records changed.');

        return $anomalies > 0 ? self::FAILURE : self::SUCCESS;
    }
}
