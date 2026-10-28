<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBladeProductMasterRequest;
use App\Models\Unit;
use App\Services\ProductMasterWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $units = Unit::query()->withCount('variants')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('admin.units.index', compact('units', 'filters'));
    }

    public function create(): View
    {
        return view('admin.units.create');
    }

    public function store(SaveBladeProductMasterRequest $request, ProductMasterWriteService $writer): RedirectResponse
    {
        $writer->create('units', $request->validated());

        return redirect()->route('admin.web.units.index')->with('success', 'Đã tạo đơn vị.');
    }

    public function edit(Unit $unit): View
    {
        return view('admin.units.edit', compact('unit'));
    }

    public function update(SaveBladeProductMasterRequest $request, Unit $unit, ProductMasterWriteService $writer): RedirectResponse
    {
        $writer->update('units', $unit, $request->validated());

        return redirect()->route('admin.web.units.edit', $unit)->with('success', 'Đã cập nhật đơn vị.');
    }
}
