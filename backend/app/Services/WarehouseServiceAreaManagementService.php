<?php

namespace App\Services;

use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use Illuminate\Support\Facades\DB;

class WarehouseServiceAreaManagementService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array{warehouse_id: int|string, province_code: string, priority: int|string} $data */
    public function create(array $data): WarehouseServiceArea
    {
        return DB::transaction(function () use ($data): WarehouseServiceArea {
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
            $area = WarehouseServiceArea::query()->create([
                'warehouse_id' => $warehouse->id,
                'province_code' => $data['province_code'],
                'priority' => $data['priority'],
            ]);
            $this->auditLogger->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_WAREHOUSE,
                $area, 'Warehouse service area created', [],
                $area->only(['warehouse_id', 'province_code', 'priority']),
                targetName: $warehouse->code.' / '.$area->province_code);

            return $area;
        });
    }

    /** @param array{priority: int|string} $data */
    public function updatePriority(WarehouseServiceArea $serviceArea, array $data): WarehouseServiceArea
    {
        return DB::transaction(function () use ($serviceArea, $data): WarehouseServiceArea {
            $area = WarehouseServiceArea::query()->with('warehouse')->lockForUpdate()->findOrFail($serviceArea->id);
            $oldPriority = (int) $area->priority;
            $newPriority = (int) $data['priority'];
            if ($oldPriority === $newPriority) {
                return $area;
            }

            $area->update(['priority' => $newPriority]);
            $this->auditLogger->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_WAREHOUSE,
                $area, 'Warehouse service area priority updated',
                ['priority' => $oldPriority], ['priority' => $newPriority],
                ['warehouse_id' => $area->warehouse_id, 'province_code' => $area->province_code],
                targetName: $area->warehouse->code.' / '.$area->province_code);

            return $area->refresh();
        });
    }
}
