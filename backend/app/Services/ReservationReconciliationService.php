<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReservationReconciliationService
{
    /** @return array<string, mixed> */
    public function report(?int $warehouseId = null): array
    {
        $expected = DB::table('inventory_reservations')
            ->select('warehouse_id', 'product_variant_id')
            ->selectRaw('SUM(original_quantity - consumed_quantity - released_quantity) AS expected_reserved')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('warehouse_id', 'product_variant_id')->get()
            ->keyBy(fn ($row): string => $row->warehouse_id.':'.$row->product_variant_id);
        $balances = DB::table('inventory_balances')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->get()->keyBy(fn ($row): string => $row->warehouse_id.':'.$row->product_variant_id);
        $rows = [];
        foreach ($expected->keys()->merge($balances->keys())->unique() as $key) {
            [$warehouse, $variant] = array_map('intval', explode(':', $key));
            $expectedQuantity = (string) ($expected[$key]->expected_reserved ?? '0.000');
            $actual = (string) ($balances[$key]->reserved_quantity ?? '0.000');
            $difference = bcsub($actual, $expectedQuantity, 3);
            $rows[] = [
                'warehouse_id' => $warehouse, 'product_variant_id' => $variant,
                'expected_reserved' => $expectedQuantity, 'actual_reserved' => $actual,
                'difference' => $difference,
            ];
        }

        return ['rows' => $rows, 'difference_count' => count(array_filter($rows, fn (array $row): bool => bccomp($row['difference'], '0', 3) !== 0))];
    }
}
