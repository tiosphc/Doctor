<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBladeProductMasterRequest;
use App\Models\Brand;
use App\Services\ProductMasterWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $brands = Brand::query()->withCount('products')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('admin.brands.index', compact('brands', 'filters'));
    }

    public function create(): View
    {
        return view('admin.brands.create');
    }

    public function store(SaveBladeProductMasterRequest $request, ProductMasterWriteService $writer): RedirectResponse
    {
        $writer->create('brands', $request->validated());

        return redirect()->route('admin.web.brands.index')->with('success', 'Đã tạo thương hiệu.');
    }

    public function edit(Brand $brand): View
    {
        return view('admin.brands.edit', compact('brand'));
    }

    public function update(SaveBladeProductMasterRequest $request, Brand $brand, ProductMasterWriteService $writer): RedirectResponse
    {
        $writer->update('brands', $brand, $request->validated());

        return redirect()->route('admin.web.brands.edit', $brand)->with('success', 'Đã cập nhật thương hiệu.');
    }
}
