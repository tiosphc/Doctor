<?php

namespace App\Services;

use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class WarehouseWriteService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $warehouse = Warehouse::create($data);
            $this->auditLogger->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_WAREHOUSE, $warehouse, 'Warehouse created', [], $warehouse->only(['code', 'name', 'status', 'is_default_sales', 'is_default_clinic']));

            return $warehouse;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            if (isset($data['code']) && $data['code'] !== $warehouse->code
                && ($warehouse->balances()->exists() || $warehouse->movements()->exists())) {
                throw new HttpResponseException(response()->json(['code' => 'WAREHOUSE_CODE_IMMUTABLE', 'message' => 'WAREHOUSE_CODE_IMMUTABLE'], 409));
            }
            $before = $warehouse->only(['code', 'name', 'status', 'is_default_sales', 'is_default_clinic']);
            $warehouse->update($data);
            $after = $warehouse->only(['code', 'name', 'status', 'is_default_sales', 'is_default_clinic']);
            $this->auditLogger->log($before['status'] === 'active' && $after['status'] === 'inactive'
                ? AuditLogger::ACTION_DEACTIVATE
                : ($before['status'] === 'inactive' && $after['status'] === 'active' ? AuditLogger::ACTION_ACTIVATE : AuditLogger::ACTION_UPDATE),
                AuditLogger::MODULE_WAREHOUSE, $warehouse, 'Warehouse updated', $before, $after);

            return $warehouse->refresh();
        });
    }
}
