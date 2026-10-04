<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Models\Product;
use App\Services\SalesPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request, SalesPromotionService $promotions): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'gift_filter' => ['nullable', 'in:all,gift_capable,gift_only,normal'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'discount_availability' => ['nullable', 'boolean'],
            'exclude_promotion_id' => ['nullable', 'integer', 'exists:sales_promotions,id'],
            'promotion_scope' => ['nullable', 'in:retail,dealer,both'],
            'promotion_tier_ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'promotion_starts_at' => ['nullable', 'date'],
            'promotion_ends_at' => ['nullable', 'date'],
        ]);
        $query = Product::query()->with(['category:id,code,name', 'brand:id,code,name', 'variants:id,product_id,unit_id,sku,variant_name,status,sellable_retail,track_inventory', 'variants.unit:id,name,symbol', 'images:id,product_id,path,is_primary,sort_order']);
        if (isset($data['search'])) {
            $search = $data['search'];
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('product_code', 'like', '%'.$search.'%')
                ->orWhereHas('variants', fn ($query) => $query->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('variant_name', 'like', '%'.$search.'%')));
        }
        foreach (['status' => 'status', 'category' => 'product_category_id', 'brand' => 'brand_id'] as $key => $column) {
            if (isset($data[$key])) {
                $query->where($column, $data[$key]);
            }
        }
        $giftFilter = $data['gift_filter'] ?? 'all';
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

        $page = $query->latest('id')->paginate($data['per_page'] ?? 20);
        if ($data['discount_availability'] ?? false) {
            $productIds = $page->getCollection()->pluck('id')->all();
            $scope = $data['promotion_scope'] ?? 'retail';
            $tierIds = isset($data['promotion_tier_ids'])
                ? array_map('intval', explode(',', $data['promotion_tier_ids'])) : [];
            $occupied = [];
            if ($scope !== 'dealer') {
                $occupied = $promotions->discountOccupancy($productIds, 'retail', null,
                    $data['exclude_promotion_id'] ?? null, $data['promotion_starts_at'] ?? null,
                    $data['promotion_ends_at'] ?? null);
            }
            if ($scope !== 'retail') {
                foreach ($tierIds === [] ? [null] : $tierIds as $tierId) {
                    $occupied += $promotions->discountOccupancy($productIds, 'dealer', $tierId,
                        $data['exclude_promotion_id'] ?? null, $data['promotion_starts_at'] ?? null,
                        $data['promotion_ends_at'] ?? null);
                }
            }
            foreach ($page->getCollection() as $product) {
                $promotion = $occupied[$product->id] ?? null;
                $product->setAttribute('active_discount_promotion', $promotion === null ? null : [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => $promotion->discount_value,
                ]);
            }
        }

        return response()->json($page);
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
