<?php

namespace App\Console\Commands;

use App\Models\SalesReturn;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('returns:reconcile {--dry-run} {--apply}')]
#[Description('Audit completed returns against fulfilled quantities and stock movements')]
class ReconcileReturns extends Command
{
    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Use either --dry-run or --apply.');

            return self::FAILURE;
        }
        $anomalies = 0;
        $items = 0;
        foreach (SalesReturn::query()->with('items.salesOrderItem.reservation')->where('status', 'completed')->cursor() as $salesReturn) {
            $orderWarehouse = DB::table('sales_orders')->where('id', $salesReturn->sales_order_id)->value('warehouse_id');
            if ($orderWarehouse !== $salesReturn->warehouse_id) {
                $anomalies++;
                $this->warn("Return {$salesReturn->return_code}: warehouse differs from Order.");
            }
            foreach ($salesReturn->items as $item) {
                $items++;
                $fulfilled = $item->salesOrderItem?->reservation?->consumed_quantity ?? '0.000';
                $returned = DB::table('sales_return_items as item')->join('sales_returns as sales_return',
                    'sales_return.id', '=', 'item.sales_return_id')
                    ->where('item.sales_order_item_id', $item->sales_order_item_id)
                    ->where('sales_return.status', 'completed')->sum('item.quantity');
                $movements = DB::table('stock_movements')->where('movement_type', 'SALES_RETURN')
                    ->where('reference_type', 'SALES_RETURN_ITEM')->where('reference_id', (string) $item->id)
                    ->where('warehouse_id', $salesReturn->warehouse_id)->get();
                $movement = $movements->sum('quantity');
                if (bccomp((string) $returned, $fulfilled, 3) > 0
                    || bccomp(bcadd($item->restock_quantity, $item->non_restock_quantity, 3), $item->quantity, 3) !== 0
                    || bccomp((string) $movement, $item->restock_quantity, 3) !== 0
                    || $movements->count() !== (bccomp($item->restock_quantity, '0', 3) > 0 ? 1 : 0)) {
                    $anomalies++;
                    $this->warn("Return {$salesReturn->return_code}, item {$item->id}: quantity or movement mismatch.");
                }
            }
        }
        $this->line("items_checked: {$items}");
        $this->line("anomalies: {$anomalies}");
        $this->info('No financial or stock ledger facts were changed.');

        return $anomalies > 0 ? self::FAILURE : self::SUCCESS;
    }
}
