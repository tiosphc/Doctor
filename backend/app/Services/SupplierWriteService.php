<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SupplierWriteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data): Supplier {
            $supplier = Supplier::create($data);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_PROCUREMENT, $supplier, 'Supplier created', [], $supplier->only(['code', 'name', 'status']));

            return $supplier;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if (isset($data['code']) && $data['code'] !== $supplier->code && $supplier->purchaseOrders()->exists()) {
                abort(409, 'SUPPLIER_CODE_IMMUTABLE');
            }
            $before = $supplier->only(['code', 'name', 'status']);
            $supplier->update($data);
            $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_PROCUREMENT, $supplier, 'Supplier updated', $before, $supplier->only(['code', 'name', 'status']));

            return $supplier;
        });
    }
}
