<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSalesPromotionRequest;
use App\Models\DealerTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Services\SalesPromotionWriteService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesPromotionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'upcoming', 'expired'])],
            'sales_scope' => ['nullable', Rule::in(['retail', 'dealer', 'both'])],
            'discount_type' => ['nullable', Rule::in(['percentage', 'fixed_amount', 'buy_a_get_b'])],
        ]);
        $at = now();
        $query = SalesPromotion::query()->with(['targets', 'giftRule', 'dealerTiers:id,code,name'])
            ->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('normalized_code', 'like', '%'.Str::upper(trim($search)).'%')
                ->orWhere('name', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, function ($query, $status) use ($at): void {
                if ($status === 'inactive') {
                    $query->where('status', 'inactive');
                } elseif ($status === 'upcoming') {
                    $query->where('status', 'active')->where('starts_at', '>', $at);
                } elseif ($status === 'expired') {
                    $query->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<', $at);
                } else {
                    $query->where('status', 'active')
                        ->where(fn ($nested) => $nested->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                        ->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
                }
            })
            ->when($filters['sales_scope'] ?? null, fn ($query, $scope) => $query->where('sales_scope', $scope))
            ->when($filters['discount_type'] ?? null, fn ($query, $type) => $query->where('discount_type', $type));

        return view('admin.sales-promotions.index', [
            'promotions' => $query->latest('id')->paginate(20)->withQueryString(), 'filters' => $filters,
        ]);
    }

    public function create(SalesPromotionWriteService $writer): View
    {
        return view('admin.sales-promotions.form', [
            'promotion' => null, 'suggestedCode' => $writer->suggestCode(), ...$this->formOptions(null),
        ]);
    }

    public function store(SaveSalesPromotionRequest $request, SalesPromotionWriteService $writer): RedirectResponse
    {
        $promotion = $this->runWrite(fn () => $writer->create($request->validated(), $request->user()));

        return redirect()->route('admin.web.sales-promotions.show', $promotion)->with('success', 'Đã tạo chương trình khuyến mãi.');
    }

    public function show(SalesPromotion $promotion): View
    {
        $promotion->load(['targets', 'giftRule.buyProduct', 'giftRule.giftProduct',
            'giftRule.buyVariant', 'giftRule.giftVariant', 'dealerTiers']);
        $promotion->loadCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')]);
        $productIds = $promotion->targets->pluck('product_id')->filter()->all();
        $categoryIds = $promotion->targets->pluck('product_category_id')->filter()->all();

        return view('admin.sales-promotions.show', [
            'promotion' => $promotion,
            'targetProducts' => Product::query()->whereKey($productIds)->get(['id', 'product_code', 'name']),
            'targetCategories' => ProductCategory::query()->whereKey($categoryIds)->get(['id', 'code', 'name']),
            'redemptions' => $promotion->redemptions()->with('salesOrder:id,order_code,order_status')
                ->latest('id')->paginate(20),
        ]);
    }

    public function edit(SalesPromotion $promotion): View
    {
        $promotion->load(['targets', 'giftRule', 'dealerTiers']);

        return view('admin.sales-promotions.form', [
            'promotion' => $promotion, 'suggestedCode' => null, ...$this->formOptions($promotion),
        ]);
    }

    public function update(SaveSalesPromotionRequest $request, SalesPromotion $promotion, SalesPromotionWriteService $writer): RedirectResponse
    {
        $this->runWrite(fn () => $writer->update($promotion, $request->validated()));

        return redirect()->route('admin.web.sales-promotions.show', $promotion)->with('success', 'Đã cập nhật chương trình khuyến mãi.');
    }

    public function activate(SalesPromotion $promotion, SalesPromotionWriteService $writer): RedirectResponse
    {
        $this->runWrite(fn () => $writer->setStatus($promotion, 'active'));

        return redirect()->route('admin.web.sales-promotions.show', $promotion)->with('success', 'Đã bật chương trình khuyến mãi.');
    }

    public function deactivate(SalesPromotion $promotion, SalesPromotionWriteService $writer): RedirectResponse
    {
        $this->runWrite(fn () => $writer->setStatus($promotion, 'inactive'));

        return redirect()->route('admin.web.sales-promotions.show', $promotion)->with('success', 'Đã tắt chương trình khuyến mãi.');
    }

    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['required', Rule::in(['products', 'categories', 'variants'])],
            'search' => ['nullable', 'string', 'max:80'],
            'product_id' => ['required_if:type,variants', 'nullable', 'integer', 'exists:products,id'],
        ]);
        $search = trim($filters['search'] ?? '');
        $items = match ($filters['type']) {
            'products' => Product::query()->where('status', 'active')
                ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                    ->where('product_code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))
                ->orderBy('name')->limit(20)->get(['id', 'product_code', 'name', 'can_be_gift', 'gift_only'])
                ->map(fn (Product $item): array => ['id' => $item->id,
                    'label' => $item->product_code.' · '.$item->name,
                    'can_be_gift' => $item->can_be_gift, 'gift_only' => $item->gift_only]),
            'categories' => ProductCategory::query()->where('status', 'active')
                ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                    ->where('code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))
                ->orderBy('name')->limit(20)->get(['id', 'code', 'name'])
                ->map(fn (ProductCategory $item): array => ['id' => $item->id,
                    'label' => $item->code.' · '.$item->name]),
            'variants' => ProductVariant::query()->where('status', 'active')->where('track_inventory', true)
                ->where('product_id', $filters['product_id'])
                ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                    ->where('sku', 'like', '%'.$search.'%')->orWhere('variant_name', 'like', '%'.$search.'%')))
                ->orderBy('sku')->limit(20)->get(['id', 'sku', 'variant_name'])
                ->map(fn (ProductVariant $item): array => ['id' => $item->id,
                    'label' => $item->sku.' · '.$item->variant_name]),
        };

        return response()->json(['data' => $items]);
    }

    /** @return array<string, mixed> */
    private function formOptions(?SalesPromotion $promotion): array
    {
        $productIds = old('product_ids', $promotion?->targets->pluck('product_id')->filter()->values()->all() ?? []);
        $categoryIds = old('category_ids', $promotion?->targets->pluck('product_category_id')->filter()->values()->all() ?? []);
        $productIds = is_array($productIds) ? array_filter($productIds, 'is_numeric') : [];
        $categoryIds = is_array($categoryIds) ? array_filter($categoryIds, 'is_numeric') : [];
        $gift = $promotion?->giftRule;

        return [
            'selectedProducts' => Product::query()->whereKey($productIds)->get(['id', 'product_code', 'name'])
                ->map(fn (Product $item): array => ['id' => $item->id, 'label' => $item->product_code.' · '.$item->name])->values()->all(),
            'selectedCategories' => ProductCategory::query()->whereKey($categoryIds)->get(['id', 'code', 'name'])
                ->map(fn (ProductCategory $item): array => ['id' => $item->id, 'label' => $item->code.' · '.$item->name])->values()->all(),
            'buyProduct' => $this->productOption(old('gift_rule.buy_product_id', $gift?->buy_product_id)),
            'giftProduct' => $this->productOption(old('gift_rule.gift_product_id', $gift?->gift_product_id)),
            'buyVariant' => $this->variantOption(old('gift_rule.buy_variant_id', $gift?->buy_variant_id)),
            'giftVariant' => $this->variantOption(old('gift_rule.gift_variant_id', $gift?->gift_variant_id)),
            'tiers' => DealerTier::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array{id: int, label: string}|null */
    private function productOption(mixed $id): ?array
    {
        if (! is_numeric($id)) {
            return null;
        }

        $item = Product::query()->find($id, ['id', 'product_code', 'name']);

        return $item === null ? null : ['id' => $item->id, 'label' => $item->product_code.' · '.$item->name];
    }

    /** @return array{id: int, label: string}|null */
    private function variantOption(mixed $id): ?array
    {
        if (! is_numeric($id)) {
            return null;
        }

        $item = ProductVariant::query()->find($id, ['id', 'sku', 'variant_name']);

        return $item === null ? null : ['id' => $item->id, 'label' => $item->sku.' · '.$item->variant_name];
    }

    private function runWrite(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'PROMOTION_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể lưu chương trình ('.$code.'). Hãy kiểm tra lại điều kiện và trạng thái.']);
        }
    }
}
