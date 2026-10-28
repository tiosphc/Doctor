<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductMasterRequest;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Services\ProductMasterWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductMasterController extends Controller
{
    /** @return class-string<Brand|ProductCategory|Unit> */
    private function model(string $kind): string
    {
        return ['categories' => ProductCategory::class, 'brands' => Brand::class, 'units' => Unit::class][$kind] ?? abort(404);
    }

    public function index(Request $request, string $kind): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->model($kind)::query();
        if (isset($validated['search'])) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$validated['search'].'%')->orWhere('code', 'like', '%'.$validated['search'].'%'));
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($validated['per_page'] ?? 20));
    }

    public function store(SaveProductMasterRequest $request, string $kind, ProductMasterWriteService $writer): JsonResponse
    {
        $model = $writer->create($kind, $request->validated());

        return response()->json(['data' => $model], 201);
    }

    public function show(string $kind, int $id): JsonResponse
    {
        return response()->json(['data' => $this->model($kind)::findOrFail($id)]);
    }

    public function update(SaveProductMasterRequest $request, string $kind, int $id, ProductMasterWriteService $writer): JsonResponse
    {
        $model = $this->model($kind)::findOrFail($id);
        $writer->update($kind, $model, $request->validated());

        return response()->json(['data' => $model->refresh()]);
    }
}
