<?php

namespace App\Http\Controllers\Web\Retail;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Services\RetailPricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request, RetailPricingService $pricing): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'sort' => ['nullable', 'in:newest,name'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->visibleVariants()
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = trim($filters['search']);
                $query->where(fn (Builder $query) => $query->where('product_variants.sku', 'like', "%{$search}%")
                    ->orWhere('product_variants.variant_name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")));
            });

        foreach (['category' => 'product_category_id', 'brand' => 'brand_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->whereHas('product', fn (Builder $product) => $product->where($column, $filters[$filter]));
            }
        }

        if (($filters['sort'] ?? 'newest') === 'name') {
            $query->orderBy(Product::query()->select('name')
                ->whereColumn('products.id', 'product_variants.product_id'))
                ->orderBy('product_variants.product_id')->orderBy('product_variants.id');
        } else {
            $query->orderByDesc('product_variants.product_id')->orderByDesc('product_variants.id');
        }

        $queryFilters = array_filter(
            array_intersect_key($filters, array_flip(['search', 'category', 'brand', 'sort'])),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
        $variants = $query->paginate(20)->appends($queryFilters);
        $variants->getCollection()->transform(fn (ProductVariant $variant): array => $this->card($variant, $pricing));

        return view('retail.shop.index', [
            'variants' => $variants,
            'filters' => $filters,
            'categories' => ProductCategory::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(string $product, RetailPricingService $pricing): View
    {
        $record = Product::query()->where('slug', $product)->where('status', 'active')
            ->where('gift_only', false)->with(['images' => fn ($query) => $query
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')])->firstOrFail();
        $variants = $this->visibleVariants()->where('product_id', $record->id)->orderBy('id')->get();
        abort_if($variants->isEmpty(), 404);

        return view('retail.shop.show', ['product' => $record,
            'variants' => $variants->map(fn (ProductVariant $variant): array => $this->card($variant, $pricing))]);
    }

    private function visibleVariants(): Builder
    {
        $now = now()->toDateTimeString();

        return ProductVariant::query()->where('status', 'active')->where('sellable_retail', true)
            ->where('track_inventory', true)
            ->whereHas('product', fn (Builder $query) => $query->where('status', 'active')->where('gift_only', false))
            ->whereHas('priceItems', fn (Builder $query) => $query->where('status', 'active')
                ->where('minimum_quantity', '1')
                ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $now))
                ->whereHas('priceList', fn (Builder $query) => $query->where('pricing_context', 'retail')
                    ->where('scope_type', 'all')->where('currency', 'VND')->where('status', 'active')
                    ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
                    ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $now))))
            ->with(['product.images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
                'unit:id,name,symbol', 'priceItems.priceList']);
    }

    /** @return array<string, mixed> */
    private function card(ProductVariant $variant, RetailPricingService $pricing): array
    {
        $image = $variant->product->images->first(fn ($image): bool => $image->product_variant_id === $variant->id)
            ?? $variant->product->images->first(fn ($image): bool => $image->product_variant_id === null)
            ?? $variant->product->images->first();

        return ['id' => $variant->id, 'sku' => $variant->sku, 'name' => $variant->product->name,
            'variant_name' => $variant->variant_name, 'slug' => $variant->product->slug,
            'unit' => $variant->unit?->symbol ?: $variant->unit?->name,
            'price' => $pricing->resolve($variant)['unit_price'], 'image_url' => $image?->url];
    }
}
