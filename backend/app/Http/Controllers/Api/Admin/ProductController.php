<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Product::query()->with(['category:id,code,name', 'brand:id,code,name', 'variants:id,product_id,unit_id,sku,variant_name,status,sellable_retail,track_inventory', 'images:id,product_id,path,is_primary,sort_order']);
        if (isset($data['search'])) {
            $search = $data['search'];
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('product_code', 'like', '%'.$search.'%')->orWhereHas('variants', fn ($query) => $query->where('sku', 'like', '%'.$search.'%')));
        }
        foreach (['status' => 'status', 'category' => 'product_category_id', 'brand' => 'brand_id'] as $key => $column) {
            if (isset($data[$key])) {
                $query->where($column, $data[$key]);
            }
        }

        return response()->json($query->latest('id')->paginate($data['per_page'] ?? 20));
    }

    public function store(SaveProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $unitId = $data['default_unit_id'];
        unset($data['default_unit_id']);
        $product = DB::transaction(function () use ($data, $unitId): Product {
            $product = Product::create([...$data, 'product_code' => 'TMP-'.Str::uuid()]);
            $product->update(['product_code' => 'PRD'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT)]);
            $product->variants()->create([
                'sku' => $product->product_code.'-DEFAULT',
                'variant_name' => 'Default',
                'unit_id' => $unitId,
                'status' => 'active',
            ]);

            return $product;
        });

        return response()->json(['data' => $this->loaded($product)], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->loaded($product)]);
    }

    public function update(SaveProductRequest $request, Product $product): JsonResponse
    {
        if ($product->wizard_key !== null && $product->status === 'draft') {
            return response()->json(['code' => 'WIZARD_DRAFT', 'message' => 'Complete this Product through the wizard.'], 409);
        }
        $product->update($request->validated());

        return response()->json(['data' => $this->loaded($product)]);
    }

    private function loaded(Product $product): Product
    {
        return $product->refresh()->load(['category', 'brand', 'variants.unit', 'images']);
    }
}
