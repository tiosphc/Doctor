<?php

namespace App\Console\Commands;

use App\Models\SalesOrder;
use App\Services\OrderPaymentSummaryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payments:reconcile-orders {--dry-run} {--apply}')]
#[Description('Audit settled payment allocations against Sales Order payment status')]
class ReconcileOrderPayments extends Command
{
    public function handle(OrderPaymentSummaryService $summaries): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply.');

            return self::FAILURE;
        }
        $counts = ['scanned' => 0, 'unpaid' => 0, 'partial' => 0, 'paid' => 0,
            'mismatched' => 0, 'overpaid' => 0, 'currency' => 0, 'orphaned' => 0,
            'allocation' => 0, 'legacy_paid' => 0, 'repaired' => 0];
        $counts['orphaned'] = DB::table('payment_allocations as allocation')
            ->leftJoin('payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->leftJoin('sales_orders as orders', 'orders.id', '=', 'allocation.sales_order_id')
            ->where(fn ($query) => $query->whereNull('payment.id')->orWhereNull('orders.id'))->count();
        $counts['currency'] = DB::table('payment_allocations as allocation')
            ->join('payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->join('sales_orders as orders', 'orders.id', '=', 'allocation.sales_order_id')
            ->whereColumn('payment.currency', '!=', 'orders.currency')->count();
        $counts['allocation'] = DB::table('payment_allocations as allocation')
            ->join('payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->select('payment.id')
            ->groupBy('payment.id', 'payment.amount')
            ->havingRaw('SUM(allocation.allocated_amount) > payment.amount')->get()->count();
        $summaries->withSettledSum(SalesOrder::query())->orderBy('id')->chunkById(200,
            function ($orders) use ($summaries, &$counts): void {
                foreach ($orders as $order) {
                    $counts['scanned']++;
                    $summary = $summaries->summary($order);
                    $counts[$summary['payment_status'] === 'partially_paid' ? 'partial' : $summary['payment_status']]++;
                    $overpaid = bccomp($summary['outstanding_amount'], '0', 2) < 0;
                    if ($overpaid) {
                        $counts['overpaid']++;
                        $this->warn("Overpaid order {$order->order_code}");
                    }
                    if ($order->payment_status === $summary['payment_status']) {
                        continue;
                    }
                    $counts['mismatched']++;
                    $legacy = $summaries->hasUnsupportedLegacyMarker($order, $summary['paid_amount']);
                    if ($legacy) {
                        $counts['legacy_paid']++;
                    }
                    $this->warn("Order {$order->order_code}: cached {$order->payment_status}, ledger {$summary['payment_status']}".
                        ($legacy ? ' (legacy marker; manual review)' : ''));
                    if (! $this->option('apply') || $legacy || $overpaid || $counts['currency'] > 0
                        || $counts['orphaned'] > 0 || $counts['allocation'] > 0) {
                        continue;
                    }
                    DB::transaction(function () use ($order, $summaries, &$counts): void {
                        $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
                        $current = $summaries->summary($locked);
                        if (bccomp($current['outstanding_amount'], '0', 2) >= 0
                            && ! $summaries->hasUnsupportedLegacyMarker($locked, $current['paid_amount'])) {
                            $locked->update(['payment_status' => $current['payment_status']]);
                            $counts['repaired']++;
                        }
                    });
                }
            });
        foreach ($counts as $label => $count) {
            $this->line("{$label}: {$count}");
        }
        $this->info($this->option('apply') ? 'Apply completed.' : 'Dry run: no records changed.');

        return $counts['overpaid'] + $counts['currency'] + $counts['orphaned'] + $counts['allocation'] + $counts['legacy_paid'] > 0
            ? self::FAILURE : self::SUCCESS;
    }
}
