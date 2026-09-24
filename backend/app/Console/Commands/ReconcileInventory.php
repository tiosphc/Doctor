<?php

namespace App\Console\Commands;

use App\Services\InventoryReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:reconcile
    {--warehouse= : Warehouse ID to inspect}
    {--sku= : Product variant ID to inspect}
    {--fail-on-difference : Return non-zero status for mismatches or missing balances}')]
#[Description('Read-only comparison of inventory balances with physical stock movements')]
class ReconcileInventory extends Command
{
    public function handle(InventoryReconciliationService $reconciliation): int
    {
        foreach (['warehouse', 'sku'] as $option) {
            if ($this->option($option) !== null && (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1)) {
                $this->error("--{$option} must be a positive ID.");

                return self::INVALID;
            }
        }
        $report = $reconciliation->report(
            $this->option('warehouse') === null ? null : (int) $this->option('warehouse'),
            $this->option('sku') === null ? null : (int) $this->option('sku'),
        );
        $this->table(['Metric', 'Count'], [
            ['Balances checked', $report['balances_checked']],
            ['Movements checked', $report['movements_checked']],
            ['Matched', $report['matched']],
            ['Mismatched', $report['mismatched']],
            ['Missing balances', $report['missing_balances']],
            ['Orphan movements', $report['orphan_movements']],
        ]);
        foreach ($report['rows'] as $row) {
            if ($row['status'] !== 'matched') {
                $this->warn("{$row['warehouse_code']} / {$row['sku']}: {$row['status']}, actual=".($row['actual_on_hand'] ?? 'missing').", expected={$row['expected_on_hand']}");
            }
        }

        return $this->option('fail-on-difference') && ($report['mismatched'] > 0 || $report['missing_balances'] > 0)
            ? self::FAILURE : self::SUCCESS;
    }
}
