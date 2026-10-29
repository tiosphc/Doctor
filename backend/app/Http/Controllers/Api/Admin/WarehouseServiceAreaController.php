<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWarehouseServiceAreaRequest;
use App\Http\Requests\Admin\UpdateWarehouseServiceAreaRequest;
use App\Models\AdministrativeProvince;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\WarehouseServiceAreaManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseServiceAreaController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'warehouses' => Warehouse::query()->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'status']),
            'provinces' => AdministrativeProvince::query()->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'province_code' => ['nullable', 'string', Rule::exists('administrative_provinces', 'code')],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $areas = WarehouseServiceArea::query()
            ->with(['warehouse:id,code,name,status', 'province:code,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($filters['province_code'] ?? null, fn ($query, $code) => $query->where('province_code', $code))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($matches) use ($search): void {
                $matches->whereHas('warehouse', fn ($warehouses) => $warehouses->where(fn ($names) => $names
                    ->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
                    ->orWhereHas('province', fn ($provinces) => $provinces->where(fn ($names) => $names
                        ->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')));
            }))
            ->orderBy('province_code')->orderBy('priority')->orderBy('warehouse_id')->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($areas);
    }

    public function store(
        StoreWarehouseServiceAreaRequest $request,
        WarehouseServiceAreaManagementService $manager,
    ): JsonResponse {
        try {
            $area = $manager->create($request->validated());
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['province_code' => 'Kho đã được gán cho tỉnh này.']);
            }

            throw $exception;
        }

        return response()->json(['data' => $area->load(['warehouse:id,code,name,status', 'province:code,name'])], 201);
    }

    public function update(
        UpdateWarehouseServiceAreaRequest $request,
        WarehouseServiceArea $serviceArea,
        WarehouseServiceAreaManagementService $manager,
    ): JsonResponse {
        $area = $manager->updatePriority($serviceArea, $request->validated());

        return response()->json(['data' => $area->load(['warehouse:id,code,name,status', 'province:code,name'])]);
    }
}
