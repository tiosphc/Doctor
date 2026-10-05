<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCatalogResource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesPromotion;
use App\Models\SalesPromotionGiftRule;
use App\Services\SalesGiftPromotionVisibilityService;
use App\Support\ProductCatalogDiagnosticStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
        ProductCatalogDiagnosticStage::mark($request, 'query');
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'sort' => ['nullable', 'in:newest,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'promotions_only' => ['nullable', 'boolean'],
        ]);
        $query = $this->visible();
        ProductCatalogDiagnosticStage::mark($request, 'promotion');
        $promotions = $this->retailDiscounts();
        ProductCatalogDiagnosticStage::mark($request, 'query');
        if ($data['promotions_only'] ?? false) {
            $giftCandidates = app(SalesGiftPromotionVisibilityService::class)->isAvailable()
                ? SalesPromotionGiftRule::query()->whereHas('promotion', fn (Builder $query) => $query
                    ->effectiveAt()->forChannel('retail'))
                    ->pluck('buy_product_id')->all()
                : [];
            $giftSummaries = app(SalesGiftPromotionVisibilityService::class)->forProducts($giftCandidates, 'retail');
            $giftProductIds = array_keys(array_filter($giftSummaries,
                fn (array $summaries): bool => collect($summaries)->contains('gift_available', true)));
            $productIds = $promotions->flatMap(fn (SalesPromotion $promotion) => $promotion->targets->pluck('product_id'))
                ->filter()->merge($giftProductIds)->unique()->all();
            $categoryIds = $promotions->flatMap(fn (SalesPromotion $promotion) => $promotion->targets->pluck('product_category_id'))
                ->filter()->unique()->all();
            if (! $promotions->contains(fn (SalesPromotion $promotion): bool => $promotion->targets->isEmpty())) {
                $query->where(fn (Builder $query) => $query->whereIn('id', $productIds)
                    ->orWhereIn('product_category_id', $categoryIds));
            }
        }
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

        ProductCatalogDiagnosticStage::mark($request, 'relationship');
        $page = $query->paginate($data['per_page'] ?? 15)->withQueryString();
        ProductCatalogDiagnosticStage::mark($request, 'promotion');
        $this->attachPromotions($page->getCollection(), $promotions);

        ProductCatalogDiagnosticStage::mark($request, 'resource');

        return ProductCatalogResource::collection($page);
    }

    public function show(string $product): ProductCatalogResource
    {
        $query = $this->visible()->where(fn (Builder $query) => $query->where('slug', $product)
            ->when(ctype_digit($product), fn (Builder $query) => $query->orWhereKey((int) $product)));

        $record = $query->firstOrFail();
        $this->attachPromotions(collect([$record]), $this->retailDiscounts());

        return new ProductCatalogResource($record);
    }

    private function attachPromotions(Collection $products, Collection $discounts): void
    {
        $visible = app(SalesGiftPromotionVisibilityService::class)
            ->forProducts($products->pluck('id')->all(), 'retail');
        foreach ($products as $product) {
            $product->setAttribute('gift_promotions', $visible[$product->id] ?? []);
            $discount = $discounts
                ->filter(fn (SalesPromotion $promotion): bool => $promotion->targets->isEmpty()
                    || $promotion->targets->contains(fn ($target): bool => $target->product_id === $product->id
                        || $target->product_category_id === $product->product_category_id))
                ->first();
            $summary = $discount === null ? null : [
                'code' => $discount->code,
                'discount_type' => $discount->discount_type,
                'discount_value' => $discount->discount_value,
                'max_discount_amount' => $discount->max_discount_amount,
                'minimum_order_amount' => $discount->minimum_order_amount,
                'total_usage_limit' => $discount->total_usage_limit,
                'per_buyer_usage_limit' => $discount->per_buyer_usage_limit,
            ];
            $product->setAttribute('retail_discount_promotion', $summary);
            $product->setAttribute('retail_discount_model', $discount);
            $product->setAttribute('retail_promotions', $summary === null ? [] : [$summary]);
        }
    }

    private function retailDiscounts(): Collection
    {
        return SalesPromotion::query()->with('targets')
            ->withCount(['redemptions as redeemed_count' => fn (Builder $query) => $query->where('status', 'redeemed')])
            ->effectiveAt()->forChannel('retail')->whereIn('discount_type', ['percentage', 'fixed_amount'])
            ->orderBy('id')->get()
            ->filter(fn (SalesPromotion $promotion): bool => $promotion->total_usage_limit === null
                || $promotion->redeemed_count < $promotion->total_usage_limit);
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

        $products = Product::query()->where('status', 'active');
        if (Schema::hasColumn('products', 'gift_only')) {
            $products->where('gift_only', false);
        }

        return $products
            ->whereHas('variants', $variants)
            ->with(['category:id,name,code', 'brand:id,name,code',
                'variants' => $variants, 'variants.unit:id,name,symbol', 'variants.product:id,status',
                'variants.priceItems' => $priced, 'variants.priceItems.priceList',
                'images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')]);
    }
}
