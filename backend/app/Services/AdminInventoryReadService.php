<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminInventoryReadService
{
    /** @param array<string, mixed> $filters @return array{products: LengthAwarePaginator, balances: Collection, low_stock_counts: array<int, int>, low_balance_ids: array<int, bool>} */
    public function overview(array $filters): array
    {
        $query = Product::query()->whereHas('variants');
        if (! empty($filters['product_id'])) {
            $query->whereKey($filters['product_id']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$search.'%')
                ->orWhere('product_code', 'like', '%'.$search.'%')
                ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('variant_name', 'like', '%'.$search.'%')));
        }
        if (! empty($filters['status'])) {
            $query->whereHas('variants', fn ($variants) => $variants->where('status', $filters['status']));
        }
        if ($filters['low_stock'] ?? false) {
            $lowVariantProducts = DB::table('inventory_balances as balance')
                ->join('product_variants as variant', 'variant.id', '=', 'balance.product_variant_id')
                ->join('products as product', 'product.id', '=', 'variant.product_id')
                ->where('variant.track_inventory', true)->where('product.track_inventory', true)
                ->whereNotNull('product.default_low_stock_threshold')
                ->whereRaw('(balance.on_hand_quantity - balance.reserved_quantity) <= product.default_low_stock_threshold')
                ->when($filters['warehouse_id'] ?? null, fn ($balances, $warehouseId) => $balances->where('balance.warehouse_id', $warehouseId))
                ->when($filters['status'] ?? null, fn ($balances, $status) => $balances->where('variant.status', $status))
                ->when($filters['search'] ?? null, fn ($balances, $search) => $balances->where(fn ($query) => $query
                    ->where('product.name', 'like', '%'.$search.'%')
                    ->orWhere('product.product_code', 'like', '%'.$search.'%')
                    ->orWhere('variant.sku', 'like', '%'.$search.'%')
                    ->orWhere('variant.variant_name', 'like', '%'.$search.'%')))
                ->select('variant.product_id');
            $query->whereIn('id', $lowVariantProducts);
        }
        $products = $query->with(['variants' => fn ($variants) => $variants
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->orderBy('id'),
            'variants.unit:id,name,symbol,decimal_precision',
            'images' => fn ($images) => $images->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')])
            ->orderBy('id')->paginate(20)->withQueryString();
        $variantIds = $products->getCollection()->flatMap(fn (Product $product) => $product->variants->pluck('id'))->all();
        $balances = InventoryBalance::query()->with('warehouse:id,code,name,status')
            ->whereIn('product_variant_id', $variantIds)
            ->when($filters['warehouse_id'] ?? null, fn ($builder, $warehouseId) => $builder->where('warehouse_id', $warehouseId))
            ->orderBy('warehouse_id')->get()->groupBy('product_variant_id');
        $lowStockCounts = [];
        $lowBalanceIds = [];
        foreach ($products as $product) {
            $count = 0;
            foreach ($product->variants as $variant) {
                $variant->setRelation('product', $product);
                $isLow = false;
                foreach ($balances->get($variant->id, collect()) as $balance) {
                    if ($this->isLowStock($balance, $variant)) {
                        $lowBalanceIds[$balance->id] = true;
                        $isLow = true;
                    }
                }
                $count += (int) $isLow;
            }
            $lowStockCounts[$product->id] = $count;
        }

        return ['products' => $products, 'balances' => $balances, 'low_stock_counts' => $lowStockCounts, 'low_balance_ids' => $lowBalanceIds];
    }

    /** @return Collection<int, InventoryBalance> */
    public function balancesFor(ProductVariant $variant): Collection
    {
        return InventoryBalance::query()->where('product_variant_id', $variant->id)
            ->with('warehouse:id,code,name,status')->orderBy('warehouse_id')->get();
    }

    /** @param array<string, mixed> $filters */
    public function movements(array $filters): LengthAwarePaginator
    {
        return StockMovement::query()->with(['warehouse:id,code,name', 'variant:id,product_id,sku,variant_name',
            'variant.product:id,product_code,name', 'actor:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($filters['product_variant_id'] ?? null, fn ($query, $id) => $query->where('product_variant_id', $id))
            ->when($filters['movement_type'] ?? null, fn ($query, $type) => $query->where('movement_type', $type))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('occurred_at', '<=', $date))
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('reference_id', 'like', '%'.$reference.'%'))
            ->latest('occurred_at')->latest('id')->paginate(20)->withQueryString();
    }

    public function isLowStock(InventoryBalance $balance, ProductVariant $variant): bool
    {
        return $variant->track_inventory && $variant->product->track_inventory
            && $variant->product->default_low_stock_threshold !== null
            && bccomp($balance->available_quantity, $variant->product->default_low_stock_threshold, 3) <= 0;
    }
}
