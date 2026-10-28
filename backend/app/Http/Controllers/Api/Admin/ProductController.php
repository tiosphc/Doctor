<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Services\AdminProductReadService;
use App\Services\AdminProductWriteService;
use App\Services\AuditLogger;
use App\Services\SalesPromotionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request, SalesPromotionService $promotions, AdminProductReadService $products): JsonResponse
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
        $page = $products->paginate($data);
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

    public function store(SaveProductRequest $request, AdminProductWriteService $writer): JsonResponse
    {
        $product = $writer->create($request->validated());

        return response()->json(['data' => $this->loaded($product)], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->loaded($product)]);
    }

    public function update(SaveProductRequest $request, Product $product, AdminProductWriteService $writer): JsonResponse
    {
        if ($product->wizard_key !== null && $product->status === 'draft') {
            return response()->json(['code' => 'WIZARD_DRAFT', 'message' => 'Complete this Product through the wizard.'], 409);
        }
        $writer->update($product, $request->validated());

        return response()->json(['data' => $this->loaded($product)]);
    }

    public function destroy(Product $product, AuditLogger $audit): JsonResponse|Response
    {
        try {
            $imagePaths = DB::transaction(function () use ($product, $audit): array {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $imagePaths = $locked->images()->pluck('path')->all();
                $variantIds = $locked->variants()->pluck('id')->all();

                if ($variantIds !== []) {
                    PriceListItem::query()->whereIn('product_variant_id', $variantIds)->delete();
                }
                $locked->images()->delete();
                $locked->variants()->delete();
                $audit->log(
                    AuditLogger::ACTION_DELETE,
                    AuditLogger::MODULE_PRODUCT,
                    $locked,
                    'Đã xóa sản phẩm '.$locked->name.'.',
                    oldValues: $locked->only(['product_code', 'name', 'status']),
                );
                $locked->delete();

                return $imagePaths;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                return response()->json([
                    'message' => 'Sản phẩm đã có dữ liệu đơn hàng, kho, giỏ hàng hoặc khuyến mãi. Hãy ngừng hoạt động thay vì xóa.',
                ], 409);
            }

            throw $exception;
        }

        Storage::disk('public')->delete($imagePaths);

        return response()->noContent();
    }

    private function loaded(Product $product): Product
    {
        return $product->refresh()->load(['category', 'brand', 'variants.unit', 'images']);
    }
}
