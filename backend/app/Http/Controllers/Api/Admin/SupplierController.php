<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Supplier::query();
        if (isset($filters['search'])) {
            $query->where(fn ($builder) => $builder->where('code', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%'));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 20));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $supplier = DB::transaction(function () use ($data, $audit): Supplier {
            $supplier = Supplier::create($data);
            $audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_PROCUREMENT, $supplier, 'Supplier created', [], $supplier->only(['code', 'name', 'status']));

            return $supplier;
        });

        return response()->json(['data' => $supplier], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $supplier);
        $supplier = DB::transaction(function () use ($data, $supplier, $audit): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if (isset($data['code']) && $data['code'] !== $supplier->code && $supplier->purchaseOrders()->exists()) {
                abort(409, 'SUPPLIER_CODE_IMMUTABLE');
            }
            $before = $supplier->only(['code', 'name', 'status']);
            $supplier->update($data);
            $audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_PROCUREMENT, $supplier, 'Supplier updated', $before, $supplier->only(['code', 'name', 'status']));

            return $supplier;
        });

        return response()->json(['data' => $supplier]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', $supplier?->code ?? '')))]);

        return $request->validate([
            'code' => [$supplier ? 'sometimes' : 'required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
