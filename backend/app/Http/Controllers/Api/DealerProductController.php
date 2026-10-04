<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\Product;
use App\Models\SalesPromotion;
use App\Services\DealerEffectivePricingService;
use App\Services\SalesGiftPromotionVisibilityService;
use App\Services\SalesPromotionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DealerProductController extends Controller
{
    public function index(Request $request, DealerAccount $dealer, DealerEffectivePricingService $pricing, SalesPromotionService $promotions): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'warehouse_id' => ['prohibited'],
        ]);
        $context = $pricing->context($request->user(), $dealer);
        $query = $this->visible($context['tier']->id);
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('product_code', 'like', '%'.$search.'%')
                ->orWhereHas('variants', fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->where('sku', 'like', '%'.$search.'%')
                        ->orWhere('variant_name', 'like', '%'.$search.'%')
                        ->orWhere('specifications', 'like', '%'.$search.'%'))
                    ->where('status', 'active')->where('sellable_dealer', true)));
        }
        $page = $query->latest('id')->paginate($filters['per_page'] ?? 15);
        $variants = $page->getCollection()->flatMap(fn (Product $product) => $product->variants);
        $prices = $pricing->catalogPrices($context, $variants);
        $giftPromotions = app(SalesGiftPromotionVisibilityService::class)
            ->forProducts($page->getCollection()->pluck('id')->all(), 'dealer', $context['tier']->id);
        $discounts = $promotions->discountOccupancy($page->getCollection()->pluck('id')->all(),
            'dealer', $context['tier']->id, effectiveNow: true);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Product $product): array => $this->serialize($product, $prices,
                $giftPromotions[$product->id] ?? [], $discounts[$product->id] ?? null, $promotions))->all(),
            'dealer_account' => $dealer->only(['id', 'code', 'legal_name']),
            'effective_tier' => $context['resolution']['effective_tier'],
            'warehouse' => null,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, DealerAccount $dealer, string $product, DealerEffectivePricingService $pricing, SalesPromotionService $promotions): JsonResponse
    {
        $request->validate(['warehouse_id' => ['prohibited']]);
        $context = $pricing->context($request->user(), $dealer);
        $record = $this->visible($context['tier']->id)
            ->where(fn (Builder $query) => $query->where('slug', $product)
                ->when(ctype_digit($product), fn (Builder $query) => $query->orWhereKey((int) $product)))
            ->firstOrFail();
        $prices = $pricing->catalogPrices($context, $record->variants);
        $giftPromotions = app(SalesGiftPromotionVisibilityService::class)
            ->forProducts([$record->id], 'dealer', $context['tier']->id);
        $discounts = $promotions->discountOccupancy([$record->id], 'dealer', $context['tier']->id,
            effectiveNow: true);

        return response()->json([
            'data' => $this->serialize($record, $prices, $giftPromotions[$record->id] ?? [],
                $discounts[$record->id] ?? null, $promotions),
            'dealer_account' => $dealer->only(['id', 'code', 'legal_name']),
            'effective_tier' => $context['resolution']['effective_tier'],
            'warehouse' => null,
        ]);
    }

    public function visible(int $tierId): Builder
    {
        $now = now()->toDateTimeString();
        $priced = static fn (Builder|Relation $query): Builder|Relation => $query->where('status', 'active')
            ->where('unit_price', '>', 0)->where('minimum_quantity', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $now))
            ->whereHas('priceList', fn (Builder $query) => $query->where('pricing_context', 'dealer')
                ->where('scope_type', 'tier')->where('dealer_tier_id', $tierId)
                ->where('currency', 'VND')->where('status', 'active')
                ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $now)));
        $sellable = static fn (Builder|Relation $query): Builder|Relation => $query
            ->where('status', 'active')->where('sellable_dealer', true);

        $products = Product::query()->where('status', 'active');
        if (Schema::hasColumn('products', 'gift_only')) {
            $products->where('gift_only', false);
        }

        return $products
            ->whereHas('variants', fn (Builder $query) => $sellable($query)->whereHas('priceItems', $priced))
            ->with(['category:id,code,name', 'brand:id,code,name',
                'variants' => $sellable, 'variants.unit:id,name,symbol,decimal_precision', 'variants.product:id,status',
                'images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')]);
    }

    /** @param array<int, array<string, mixed>> $prices @param list<array<string, mixed>> $giftPromotions @return array<string, mixed> */
    private function serialize(Product $product, array $prices, array $giftPromotions,
        ?SalesPromotion $discount, SalesPromotionService $promotions): array
    {
        $summary = $discount === null ? null : [
            'id' => $discount->id, 'code' => $discount->code, 'name' => $discount->name,
            'discount_type' => $discount->discount_type, 'discount_value' => $discount->discount_value,
            'minimum_order_amount' => $discount->minimum_order_amount,
        ];

        return [
            'id' => $product->id, 'product_code' => $product->product_code, 'name' => $product->name,
            'slug' => $product->slug, 'description' => $product->description,
            'gift_promotions' => $giftPromotions,
            'dealer_discount_promotion' => $summary,
            'active_promotions' => $summary === null ? [] : [$summary],
            'category' => $product->category?->only(['id', 'code', 'name']),
            'brand' => $product->brand?->only(['id', 'code', 'name']),
            'images' => $product->images->map(fn ($image): array => [
                'id' => $image->id, 'url' => url(Storage::disk('public')->url($image->path)),
                'alt_text' => $image->alt_text, 'product_variant_id' => $image->product_variant_id,
                'is_primary' => $image->is_primary,
            ])->all(),
            'variants' => $product->variants->filter(fn ($variant): bool => isset($prices[$variant->id]))
                ->map(fn ($variant): array => [
                    'id' => $variant->id, 'sku' => $variant->sku, 'variant_name' => $variant->variant_name,
                    'specifications' => $variant->specifications,
                    'unit' => $variant->unit?->name, 'unit_symbol' => $variant->unit?->symbol,
                    'unit_precision' => $variant->unit?->decimal_precision,
                    'dealer_price' => [...$prices[$variant->id],
                        'discounted_unit_price' => $discount !== null
                            && bccomp($prices[$variant->id]['unit_price'], $discount->minimum_order_amount, 2) >= 0
                                ? $promotions->discountedUnitPrice($prices[$variant->id]['unit_price'], $discount)
                                : null],
                ])->values()->all(),
        ];
    }
}
