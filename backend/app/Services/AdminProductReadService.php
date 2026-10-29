<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class AdminProductReadService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, bool $summaryOnly = false): LengthAwarePaginator
    {
        $query = Product::query();
        if ($summaryOnly) {
            $query->with(['category:id,code,name', 'brand:id,code,name',
                'images:id,product_id,product_variant_id,path,alt_text,is_primary,sort_order'])
                ->withCount('variants');
        } else {
            $query->with(['category:id,code,name', 'brand:id,code,name',
                'variants:id,product_id,unit_id,sku,variant_name,status,sellable_retail,track_inventory',
                'variants.unit:id,name,symbol',
                'images:id,product_id,path,is_primary,sort_order']);
        }

        if (! isset($filters['search']) && ($filters['status'] ?? null) !== 'draft') {
            $query->where('status', '!=', 'draft');
        } elseif (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($products) => $products->where('name', 'like', '%'.$search.'%')
                ->orWhere('product_code', 'like', '%'.$search.'%')
                ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('variant_name', 'like', '%'.$search.'%')));
        }
        foreach (['status' => 'status', 'category' => 'product_category_id', 'brand' => 'brand_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        $giftFilter = $filters['gift_filter'] ?? 'all';
        if ($giftFilter !== 'all') {
            if (! Schema::hasColumns('products', ['can_be_gift', 'gift_only'])) {
                if ($giftFilter !== 'normal') {
                    $query->whereKey(-1);
                }
            } elseif ($giftFilter === 'gift_capable') {
                $query->where('can_be_gift', true);
            } else {
                $query->where('gift_only', $giftFilter === 'gift_only');
            }
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 20);
    }
}
