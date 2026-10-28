<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWarehouseServiceAreaRequest;
use App\Http\Requests\Admin\UpdateWarehouseServiceAreaRequest;
use App\Models\AdministrativeProvince;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\WarehouseServiceAreaManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WarehouseServiceAreaController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'province_code' => ['nullable', 'string', Rule::exists('administrative_provinces', 'code')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $areas = WarehouseServiceArea::query()->with(['warehouse:id,code,name,status', 'province:code,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($filters['province_code'] ?? null, fn ($query, $code) => $query->where('province_code', $code))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($matches) use ($search): void {
                $matches->whereHas('warehouse', fn ($warehouses) => $warehouses->where(fn ($names) => $names
                    ->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
                    ->orWhereHas('province', fn ($provinces) => $provinces->where(fn ($names) => $names
                        ->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')));
            }))
            ->orderBy('province_code')->orderBy('priority')->orderBy('warehouse_id')->orderBy('id')
            ->paginate(20)->withQueryString();

        return view('admin.warehouse-service-areas.index', [
            'areas' => $areas,
            'filters' => $filters,
            ...$this->options(),
        ]);
    }

    public function create(): View
    {
        return view('admin.warehouse-service-areas.create', $this->options());
    }

    public function store(StoreWarehouseServiceAreaRequest $request, WarehouseServiceAreaManagementService $manager): RedirectResponse
    {
        try {
            $manager->create($request->validated());
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['province_code' => 'Kho đã được gán cho tỉnh này.']);
            }

            throw $exception;
        }

        return redirect()->route('admin.web.warehouse-service-areas.index')->with('success', 'Đã thêm vùng phục vụ.');
    }

    public function edit(WarehouseServiceArea $serviceArea): View
    {
        return view('admin.warehouse-service-areas.edit', [
            'serviceArea' => $serviceArea->load(['warehouse', 'province']),
        ]);
    }

    public function update(UpdateWarehouseServiceAreaRequest $request, WarehouseServiceArea $serviceArea, WarehouseServiceAreaManagementService $manager): RedirectResponse
    {
        $manager->updatePriority($serviceArea, $request->validated());

        return redirect()->route('admin.web.warehouse-service-areas.edit', $serviceArea)
            ->with('success', 'Đã cập nhật độ ưu tiên.');
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'warehouses' => Warehouse::query()->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'status']),
            'provinces' => AdministrativeProvince::query()->orderBy('name')->get(['code', 'name']),
        ];
    }
}
