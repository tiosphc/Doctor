<?php

namespace App\Services;

use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductPricingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function show(Product $product): array
    {
        $variants = $product->variants()->orderBy('id')->get();
        $retail = $variants->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $this->currentItem($variant, 'retail')]);
        $default = $retail->first(fn (?PriceListItem $item): bool => $item !== null)?->unit_price;
        $tiers = DealerTier::query()->where('status', 'active')->orderBy('id')->get();
        $dealerRules = [];
        foreach ($variants as $variant) {
            foreach ($tiers as $tier) {
                $item = $this->currentItem($variant, 'dealer', $tier->id);
                if ($item !== null) {
                    $dealerRules[] = [
                        'tier_id' => $tier->id, 'sku' => $variant->sku,
                        'min_quantity' => (string) (int) $item->minimum_quantity,
                        'unit_price' => $item->unit_price,
                    ];
                }
            }
        }

        return [
            'sellable_retail' => $variants->contains('sellable_retail', true),
            'sellable_dealer' => $variants->contains('sellable_dealer', true),
            'retail_price' => $default,
            'variant_retail_prices' => $variants->map(fn (ProductVariant $variant): array => [
                'sku' => $variant->sku,
                'unit_price' => $retail->get($variant->id)?->unit_price === $default ? null : $retail->get($variant->id)?->unit_price,
            ])->all(),
            'dealer_rules' => $dealerRules,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function catalog(string $context, array $filters): LengthAwarePaginator
    {
        $tiers = $context === 'dealer'
            ? DealerTier::query()->where('status', 'active')->when($filters['tier_id'] ?? null, fn ($query, $tierId) => $query->whereKey($tierId))->orderBy('sort_order')->get()
            : collect();
        $query = ProductVariant::query()->with([
            'product:id,product_code,name,status',
            'product.images' => fn ($images) => $images
                ->select('id', 'product_id', 'path', 'is_primary', 'sort_order')
                ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            'unit:id,name,symbol',
        ]);
        if ($context === 'dealer' && $tiers->isEmpty()) {
            $query->whereRaw('1 = 0');
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('sku', 'like', '%'.$search.'%')
                ->orWhere('variant_name', 'like', '%'.$search.'%')
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', '%'.$search.'%')
                    ->orWhere('product_code', 'like', '%'.$search.'%')));
        }
        if (! empty($filters['status'])) {
            $sellableColumn = $context === 'retail' ? 'sellable_retail' : 'sellable_dealer';
            if ($filters['status'] === 'active') {
                $query->where('status', 'active')->where($sellableColumn, true)
                    ->whereHas('product', fn ($product) => $product->where('status', 'active'));
            } else {
                $query->where(fn ($query) => $query->where('status', '!=', 'active')
                    ->orWhere($sellableColumn, false)
                    ->orWhereHas('product', fn ($product) => $product->where('status', '!=', 'active')));
            }
        }
        if (! empty($filters['price_status'])) {
            $at = now()->toDateTimeString();
            if ($context === 'retail') {
                $priceFilter = fn (Builder $items): Builder => $this->currentItemsScope($items, 'retail', null, $at);
                if ($filters['price_status'] === 'priced') {
                    $query->whereHas('priceItems', $priceFilter);
                } else {
                    $query->whereDoesntHave('priceItems', $priceFilter);
                }
            } elseif ($filters['price_status'] === 'priced') {
                foreach ($tiers as $tier) {
                    $query->whereHas('priceItems', fn (Builder $items): Builder => $this->currentItemsScope($items, 'dealer', $tier->id, $at));
                }
            } else {
                $query->where(function (Builder $missing) use ($tiers, $at): void {
                    foreach ($tiers as $tier) {
                        $missing->orWhereDoesntHave('priceItems', fn (Builder $items): Builder => $this->currentItemsScope($items, 'dealer', $tier->id, $at));
                    }
                });
            }
        }
        $page = $query->orderBy('id')->paginate($filters['per_page'] ?? 20);
        $balances = isset($filters['warehouse_id']) && $context === 'retail'
            ? DB::table('inventory_balances')
                ->where('warehouse_id', $filters['warehouse_id'])
                ->whereIn('product_variant_id', collect($page->items())->pluck('id'))
                ->get()->keyBy('product_variant_id')
            : collect();
        $rows = [];
        foreach ($page->items() as $variant) {
            if ($context === 'retail') {
                $balance = $balances->get($variant->id);
                $available = $balance === null ? '0.000'
                    : bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
                $rows[] = $this->catalogRow($variant, 'retail', availableQuantity: isset($filters['warehouse_id'])
                    ? (bccomp($available, '0', 3) < 0 ? '0.000' : $available) : null);
            } else {
                $retailReference = $this->currentItem($variant, 'retail')?->unit_price;
                foreach ($tiers as $tier) {
                    $rows[] = $this->catalogRow($variant, 'dealer', $tier, $retailReference);
                }
            }
        }
        $page->setCollection(collect($rows));

        return $page;
    }

    /** @return array<string, mixed> */
    private function catalogRow(ProductVariant $variant, string $context, ?DealerTier $tier = null, ?string $retailReference = null, ?string $availableQuantity = null): array
    {
        $item = $this->currentItem($variant, $context, $tier?->id);

        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'product_code' => $variant->product->product_code,
            'product_name' => $variant->product->name,
            'product_image_url' => $variant->product->images->first()?->url,
            'sku' => $variant->sku,
            'variant_name' => $variant->variant_name,
            'unit_symbol' => $variant->unit?->symbol,
            'status' => $variant->status,
            'product_status' => $variant->product->status,
            'sellable' => $context === 'retail' ? $variant->sellable_retail : $variant->sellable_dealer,
            'track_inventory' => $variant->track_inventory,
            'available_quantity' => $availableQuantity,
            'tier_id' => $tier?->id,
            'tier_name' => $tier?->name,
            'unit_price' => $item?->unit_price,
            'minimum_quantity' => $item === null ? null : (int) $item->minimum_quantity,
            'retail_reference_price' => $retailReference,
        ];
    }

    public function currentItem(ProductVariant $variant, string $context, ?int $tierId = null): ?PriceListItem
    {
        return $this->currentItemsScope(
            PriceListItem::query()->with('priceList')->where('product_variant_id', $variant->id),
            $context,
            $tierId,
            now()->toDateTimeString(),
        )->get()->sort(fn (PriceListItem $left, PriceListItem $right): int => $right->priceList->priority <=> $left->priceList->priority ?: $right->id <=> $left->id)
            ->first();
    }

    private function currentItemsScope(Builder $query, string $context, ?int $tierId, string $at): Builder
    {
        return $query->where('status', 'active')
            ->when($context === 'retail', fn ($query) => $query->where('minimum_quantity', 1))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at))
            ->whereHas('priceList', fn ($query) => $query->where('pricing_context', $context)
                ->where('scope_type', $context === 'retail' ? 'all' : 'tier')
                ->where('currency', 'VND')->where('status', 'active')
                ->when($context === 'dealer', fn ($query) => $tierId === null
                    ? $query->whereIn('dealer_tier_id', DealerTier::query()->where('status', 'active')->select('id'))
                    : $query->where('dealer_tier_id', $tierId))
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at)));
    }

    /** @param array<string, mixed> $data */
    public function save(Product $product, array $data, int $actorId): void
    {
        DB::transaction(function () use ($product, $data, $actorId): void {
            PriceList::query()->orderBy('id')->lockForUpdate()->get();
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($locked->status === 'draft' && $locked->wizard_key !== null) {
                throw new HttpResponseException(response()->json(['code' => 'WIZARD_DRAFT', 'message' => 'Complete this Product through the wizard.'], 409));
            }
            $variants = $locked->variants()->orderBy('id')->lockForUpdate()->get();
            $overrides = collect($data['variant_retail_prices'] ?? [])->keyBy('sku');
            $rules = collect($data['dealer_rules'] ?? [])->keyBy(fn (array $rule): string => $rule['tier_id'].':'.$rule['sku']);
            $tiers = DealerTier::query()->pluck('id');
            foreach ($variants as $variant) {
                $variant->update(['sellable_retail' => $data['sellable_retail'], 'sellable_dealer' => $data['sellable_dealer']]);
                $retailPrice = $data['sellable_retail'] ? ($overrides->get($variant->sku)['unit_price'] ?? $data['retail_price']) : null;
                $this->replacePrice($variant, 'retail', $retailPrice, 1, null, $actorId);
                foreach ($tiers as $tierId) {
                    $rule = $data['sellable_dealer'] ? $rules->get($tierId.':'.$variant->sku) : null;
                    $this->replacePrice($variant, 'dealer', $rule['unit_price'] ?? null, (int) ($rule['min_quantity'] ?? 1), (int) $tierId, $actorId);
                }
            }
        }, 3);
    }

    public function updateRetail(ProductVariant $variant, string $price, int $actorId): void
    {
        DB::transaction(function () use ($variant, $price, $actorId): void {
            PriceList::query()->orderBy('id')->lockForUpdate()->get();
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $this->replacePrice($locked, 'retail', $price, 1, null, $actorId);
        }, 3);
    }

    public function updateDealer(ProductVariant $variant, DealerTier $tier, ?string $price, int $minimumQuantity, int $actorId): void
    {
        DB::transaction(function () use ($variant, $tier, $price, $minimumQuantity, $actorId): void {
            PriceList::query()->orderBy('id')->lockForUpdate()->get();
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $this->replacePrice($locked, 'dealer', $price, $minimumQuantity, $tier->id, $actorId);
        }, 3);
    }

    public function addInitialPrice(ProductVariant $variant, string $context, string $price, int $minimumQuantity = 1, ?int $tierId = null): void
    {
        $list = $this->canonicalList($context, $tierId);
        $list->items()->create(['product_variant_id' => $variant->id, 'unit_price' => $price, 'minimum_quantity' => $minimumQuantity]);
    }

    private function replacePrice(ProductVariant $variant, string $context, ?string $price, int $minimumQuantity, ?int $tierId, int $actorId): void
    {
        $old = $this->currentItem($variant, $context, $tierId);
        $matching = PriceListItem::query()->where('product_variant_id', $variant->id)->where('status', 'active')
            ->whereHas('priceList', fn ($query) => $query->where('pricing_context', $context)
                ->when($context === 'dealer', fn ($query) => $query->where('dealer_tier_id', $tierId)));
        if ($price === null && ! (clone $matching)->exists()) {
            return;
        }
        if ($price !== null && $old !== null && bccomp($old->unit_price, $price, 2) === 0
            && ($context === 'retail' || bccomp($old->minimum_quantity, (string) $minimumQuantity, 3) === 0)) {
            return;
        }
        $matching->update(['status' => 'inactive']);
        if ($price !== null) {
            $this->addInitialPrice($variant, $context, $price, $minimumQuantity, $tierId);
        }
        $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_PRODUCT, $variant, 'Product SKU price updated',
            ['unit_price' => $old?->unit_price, 'minimum_quantity' => $old?->minimum_quantity],
            ['unit_price' => $price, 'minimum_quantity' => $price === null ? null : $minimumQuantity],
            ['sku' => $variant->sku, 'pricing_context' => $context, 'tier_id' => $tierId, 'actor_id' => $actorId]);
    }

    private function canonicalList(string $context, ?int $tierId): PriceList
    {
        $code = $context === 'retail' ? 'RETAIL-CATALOG-VND' : 'DEALER-TIER-'.$tierId.'-VND';

        $list = PriceList::query()->firstOrCreate(['code' => $code], [
            'name' => $context === 'retail' ? 'Retail catalog' : 'Dealer tier '.$tierId,
            'pricing_context' => $context, 'scope_type' => $context === 'retail' ? 'all' : 'tier',
            'dealer_tier_id' => $tierId, 'currency' => 'VND', 'priority' => 0, 'status' => 'active',
        ]);
        if ($list->pricing_context !== $context || $list->scope_type !== ($context === 'retail' ? 'all' : 'tier')
            || $list->dealer_tier_id !== $tierId || $list->currency !== 'VND' || $list->status !== 'active') {
            throw new HttpResponseException(response()->json(['code' => 'PRICE_BOOK_CONFLICT', 'message' => 'PRICE_BOOK_CONFLICT'], 409));
        }

        return $list;
    }
}
