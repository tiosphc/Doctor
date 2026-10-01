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
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'sort' => ['nullable', 'in:newest,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'promotions_only' => ['nullable', 'boolean'],
        ]);
        $query = $this->visible();
        $promotions = $this->retailDiscounts();
        if ($data['promotions_only'] ?? false) {
            $giftCandidates = app(SalesGiftPromotionVisibilityService::class)->isAvailable()
                ? SalesPromotionGiftRule::query()->whereHas('promotion', fn (Builder $query) => $query
                    ->where('status', 'active')->whereIn('sales_scope', ['retail', 'both'])
                    ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now())))
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

        $page = $query->paginate($data['per_page'] ?? 15)->withQueryString();
        $this->attachPromotions($page->getCollection(), $promotions);

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
            $product->setAttribute('retail_promotions', $discounts
                ->filter(fn (SalesPromotion $promotion): bool => $promotion->targets->isEmpty()
                    || $promotion->targets->contains(fn ($target): bool => $target->product_id === $product->id
                        || $target->product_category_id === $product->product_category_id))
                ->map(fn (SalesPromotion $promotion): array => [
                    'code' => $promotion->code,
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => $promotion->discount_value,
                    'max_discount_amount' => $promotion->max_discount_amount,
                    'minimum_order_amount' => $promotion->minimum_order_amount,
                    'total_usage_limit' => $promotion->total_usage_limit,
                    'per_buyer_usage_limit' => $promotion->per_buyer_usage_limit,
                ])->values()->all());
        }
    }

    private function retailDiscounts(): Collection
    {
        $now = now();

        return SalesPromotion::query()->with('targets')
            ->withCount(['redemptions as redeemed_count' => fn (Builder $query) => $query->where('status', 'redeemed')])
            ->where('status', 'active')->whereIn('sales_scope', ['retail', 'both'])
            ->whereIn('discount_type', ['percentage', 'fixed_amount'])
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
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
