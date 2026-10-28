<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBladeProductMasterRequest;
use App\Models\ProductCategory;
use App\Services\ProductMasterWriteService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $categories = ProductCategory::query()
            ->with('parent:id,name')
            ->withCount('products')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('admin.categories.index', compact('categories', 'filters'));
    }

    public function create(): View
    {
        return view('admin.categories.create', ['parents' => $this->parentOptions()]);
    }

    public function store(SaveBladeProductMasterRequest $request, ProductMasterWriteService $writer): RedirectResponse
    {
        $writer->create('categories', $request->validated());

        return redirect()->route('admin.web.categories.index')->with('success', 'Đã tạo danh mục.');
    }

    public function edit(ProductCategory $category): View
    {
        return view('admin.categories.edit', [
            'category' => $category,
            'parents' => $this->parentOptions($category),
        ]);
    }

    public function update(SaveBladeProductMasterRequest $request, ProductCategory $category, ProductMasterWriteService $writer): RedirectResponse
    {
        $writer->update('categories', $category, $request->validated());

        return redirect()->route('admin.web.categories.edit', $category)->with('success', 'Đã cập nhật danh mục.');
    }

    private function parentOptions(?ProductCategory $category = null): Collection
    {
        return ProductCategory::query()->when($category, fn ($query) => $query->where('id', '!=', $category->id))
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'status']);
    }
}
