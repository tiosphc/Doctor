<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBladeWarehouseRequest;
use App\Models\Warehouse;
use App\Services\WarehouseWriteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', 'in:name,code,newest'],
        ]);
        $warehouses = Warehouse::query()->withCount('balances')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        match ($filters['sort'] ?? 'name') {
            'code' => $warehouses->orderBy('code'),
            'newest' => $warehouses->latest('id'),
            default => $warehouses->orderBy('name'),
        };

        return view('admin.warehouses.index', [
            'warehouses' => $warehouses->orderBy('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.warehouses.create');
    }

    public function store(SaveBladeWarehouseRequest $request, WarehouseWriteService $writer): RedirectResponse
    {
        try {
            $writer->create($request->validated());
        } catch (QueryException $exception) {
            $this->rethrowConflict($exception);
        }

        return redirect()->route('admin.web.warehouses.index')->with('success', 'Đã tạo kho.');
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('admin.warehouses.edit', compact('warehouse'));
    }

    public function update(SaveBladeWarehouseRequest $request, Warehouse $warehouse, WarehouseWriteService $writer): RedirectResponse
    {
        try {
            $writer->update($warehouse, $request->validated());
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() === 409) {
                throw ValidationException::withMessages(['code' => 'Không thể đổi mã kho đã có lịch sử tồn kho.']);
            }
            throw $exception;
        } catch (QueryException $exception) {
            $this->rethrowConflict($exception);
        }

        return redirect()->route('admin.web.warehouses.edit', $warehouse)->with('success', 'Đã cập nhật kho.');
    }

    private function rethrowConflict(QueryException $exception): never
    {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            throw ValidationException::withMessages(['code' => 'Mã kho hoặc kho mặc định đang hoạt động đã tồn tại.']);
        }

        throw $exception;
    }
}
