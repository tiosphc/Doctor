<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCatalogResource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductCatalogController extends Controller
{
    public function filters(): JsonResponse
    {
        return response()->json([
            'categories' => ProductCategory::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'brands' => Brand::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'sort' => ['nullable', 'in:newest,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->visible();
        if (isset($data['search'])) {
            $search = $data['search'];
            $query->where(fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('product_code', 'like', '%'.$search.'%')
                ->orWhereHas('variants', fn (Builder $query) => $query->where('sku', 'like', '%'.$search.'%')->where('status', 'active')->where('sellable_retail', true)));
        }
        foreach (['category' => 'product_category_id', 'brand' => 'brand_id'] as $key => $column) {
            if (isset($data[$key])) {
                $query->where($column, $data[$key]);
            }
        }
        if (($data['sort'] ?? 'newest') === 'name') {
            $query->orderBy('name')->orderBy('id');
        } else {
            $query->latest('id');
        }

        return ProductCatalogResource::collection($query->paginate($data['per_page'] ?? 15)->withQueryString());
    }

    public function show(string $product): ProductCatalogResource
    {
        $query = $this->visible()->where(fn (Builder $query) => $query->where('slug', $product)
            ->when(ctype_digit($product), fn (Builder $query) => $query->orWhereKey((int) $product)));

        return new ProductCatalogResource($query->firstOrFail());
    }

    private function visible(): Builder
    {
        $now = now()->toDateTimeString();
        $priced = static fn (Builder|Relation $query): Builder|Relation => $query
            ->where('minimum_quantity', '1')
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $now))
            ->whereHas('priceList', fn (Builder $query) => $query
                ->where('pricing_context', 'retail')->where('scope_type', 'all')->where('currency', 'VND')->where('status', 'active')
                ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $now)));
        $variants = static fn (Builder|Relation $query): Builder|Relation => $query
            ->where('status', 'active')->where('sellable_retail', true)->whereHas('priceItems', $priced);

        return Product::query()
            ->where('status', 'active')
            ->whereHas('variants', $variants)
            ->with(['category:id,name,code', 'brand:id,name,code',
                'variants' => $variants, 'variants.unit:id,name,symbol', 'variants.product:id,status',
                'variants.priceItems' => $priced, 'variants.priceItems.priceList',
                'images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')]);
    }
}
