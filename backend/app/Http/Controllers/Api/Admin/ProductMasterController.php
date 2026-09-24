<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductMasterRequest;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function store(SaveProductMasterRequest $request, string $kind): JsonResponse
    {
        $data = $request->validated();
        if ($kind === 'categories') {
            $this->assertValidParent($data['parent_id'] ?? null, null);
        }
        $model = $this->model($kind)::create($data);

        return response()->json(['data' => $model], 201);
    }

    public function show(string $kind, int $id): JsonResponse
    {
        return response()->json(['data' => $this->model($kind)::findOrFail($id)]);
    }

    public function update(SaveProductMasterRequest $request, string $kind, int $id): JsonResponse
    {
        $model = $this->model($kind)::findOrFail($id);
        $data = $request->validated();
        if ($kind === 'categories' && array_key_exists('parent_id', $data)) {
            $this->assertValidParent($data['parent_id'], $id);
        }
        $model->update($data);

        return response()->json(['data' => $model->refresh()]);
    }

    private function assertValidParent(?int $parentId, ?int $selfId): void
    {
        for ($depth = 0; $parentId !== null; $depth++) {
            if ($depth >= 20 || $parentId === $selfId) {
                throw ValidationException::withMessages(['parent_id' => 'Category hierarchy cannot contain a cycle or exceed 20 levels.']);
            }
            $parentId = ProductCategory::findOrFail($parentId)->parent_id;
        }
    }
}
