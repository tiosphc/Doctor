<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class ProductMasterWriteService
{
    /** @param array<string, mixed> $data */
    public function create(string $kind, array $data): Brand|ProductCategory|Unit
    {
        if ($kind === 'categories') {
            $this->assertValidParent($data['parent_id'] ?? null, null);
        }

        return $this->model($kind)::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(string $kind, Brand|ProductCategory|Unit $model, array $data): Brand|ProductCategory|Unit
    {
        $modelClass = $this->model($kind);
        abort_unless($model instanceof $modelClass, 404);
        if ($kind === 'categories' && array_key_exists('parent_id', $data)) {
            $this->assertValidParent($data['parent_id'], $model->id);
        }
        $model->update($data);

        return $model->refresh();
    }

    /** @return class-string<Brand|ProductCategory|Unit> */
    private function model(string $kind): string
    {
        return ['categories' => ProductCategory::class, 'brands' => Brand::class, 'units' => Unit::class][$kind] ?? abort(404);
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
