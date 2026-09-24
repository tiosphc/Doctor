<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
    /** @return array{balances_checked: int, movements_checked: int, matched: int, mismatched: int, missing_balances: int, orphan_movements: int, rows: list<array<string, mixed>>} */
    public function report(?int $warehouseId = null, ?int $variantId = null): array
    {
        $movementTotals = DB::table('stock_movements')
            ->select('warehouse_id', 'product_variant_id')
            ->selectRaw('SUM(quantity) AS expected_on_hand, COUNT(*) AS movement_count')
            ->groupBy('warehouse_id', 'product_variant_id');

        $balances = DB::table('inventory_balances as balance')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'balance.warehouse_id')
            ->join('product_variants as variant', 'variant.id', '=', 'balance.product_variant_id')
            ->leftJoinSub($movementTotals, 'ledger', fn ($join) => $join
                ->on('ledger.warehouse_id', '=', 'balance.warehouse_id')
                ->on('ledger.product_variant_id', '=', 'balance.product_variant_id'))
            ->when($warehouseId, fn ($query) => $query->where('balance.warehouse_id', $warehouseId))
            ->when($variantId, fn ($query) => $query->where('balance.product_variant_id', $variantId))
            ->orderBy('balance.warehouse_id')->orderBy('balance.product_variant_id')
            ->get(['balance.warehouse_id', 'warehouse.code as warehouse_code', 'balance.product_variant_id',
                'variant.sku', 'balance.on_hand_quantity as actual_on_hand', 'ledger.expected_on_hand', 'ledger.movement_count']);

        $missing = DB::query()->fromSub($movementTotals, 'ledger')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'ledger.warehouse_id')
            ->join('product_variants as variant', 'variant.id', '=', 'ledger.product_variant_id')
            ->leftJoin('inventory_balances as balance', function ($join): void {
                $join->on('balance.warehouse_id', '=', 'ledger.warehouse_id')
                    ->on('balance.product_variant_id', '=', 'ledger.product_variant_id');
            })
            ->whereNull('balance.id')
            ->when($warehouseId, fn ($query) => $query->where('ledger.warehouse_id', $warehouseId))
            ->when($variantId, fn ($query) => $query->where('ledger.product_variant_id', $variantId))
            ->orderBy('ledger.warehouse_id')->orderBy('ledger.product_variant_id')
            ->get(['ledger.warehouse_id', 'warehouse.code as warehouse_code', 'ledger.product_variant_id',
                'variant.sku', 'ledger.expected_on_hand', 'ledger.movement_count']);

        $rows = [];
        $matched = 0;
        $mismatched = 0;
        $movementsChecked = 0;
        foreach ($balances as $balance) {
            $expected = (string) ($balance->expected_on_hand ?? '0');
            $actual = (string) $balance->actual_on_hand;
            $difference = bcsub($actual, $expected, 3);
            $status = bccomp($difference, '0', 3) === 0 ? 'matched' : 'mismatched';
            $status === 'matched' ? $matched++ : $mismatched++;
            $movementsChecked += (int) ($balance->movement_count ?? 0);
            $rows[] = [
                'warehouse_id' => $balance->warehouse_id, 'warehouse_code' => $balance->warehouse_code,
                'product_variant_id' => $balance->product_variant_id, 'sku' => $balance->sku,
                'actual_on_hand' => bcadd($actual, '0', 3), 'expected_on_hand' => bcadd($expected, '0', 3),
                'difference' => $difference, 'movement_count' => (int) ($balance->movement_count ?? 0),
                'status' => $status,
            ];
        }
        foreach ($missing as $movement) {
            $movementsChecked += (int) $movement->movement_count;
            $rows[] = [
                'warehouse_id' => $movement->warehouse_id, 'warehouse_code' => $movement->warehouse_code,
                'product_variant_id' => $movement->product_variant_id, 'sku' => $movement->sku,
                'actual_on_hand' => null, 'expected_on_hand' => bcadd((string) $movement->expected_on_hand, '0', 3),
                'difference' => null, 'movement_count' => (int) $movement->movement_count,
                'status' => 'missing_balance',
            ];
        }

        return [
            'balances_checked' => $balances->count(),
            'movements_checked' => $movementsChecked,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'missing_balances' => $missing->count(),
            'orphan_movements' => $missing->sum('movement_count'),
            'rows' => $rows,
        ];
    }
}
