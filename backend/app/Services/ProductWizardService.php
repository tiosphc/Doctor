<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Sku;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductWizardService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit, private readonly ProductPricingService $pricing) {}

    /** @param array<string, mixed> $data */
    public function complete(Product $draft, array $data, int $actorId): Product
    {
        return DB::transaction(function () use ($draft, $data, $actorId): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($draft->id);
            if ($product->status === 'active' && $product->wizard_key !== null) {
                if ($product->wizard_data != $data) {
                    throw new HttpResponseException(response()->json(['code' => 'WIZARD_ALREADY_COMPLETED', 'message' => 'Product wizard was already completed with different data.'], 409));
                }

                return $product;
            }
            if ($product->status !== 'draft' || $product->wizard_key === null) {
                throw ValidationException::withMessages(['data' => 'Bản nháp không còn hợp lệ.']);
            }
            if (! $product->images()->exists()) {
                throw ValidationException::withMessages(['data.images' => 'Vui lòng tải lên ít nhất một hình ảnh sản phẩm.']);
            }

            $product->update([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.$product->id,
                'description' => $data['description'] ?? null,
                'product_category_id' => $data['product_category_id'],
                'brand_id' => $data['brand_id'] ?? null,
                'base_sku' => $data['sku'],
                'track_inventory' => $data['track_inventory'],
                'can_be_gift' => $data['can_be_gift'] ?? false,
                'gift_only' => $data['gift_only'] ?? false,
                'default_low_stock_threshold' => $data['low_stock_threshold'] ?? null,
                'youtube_videos' => $data['youtube_videos'] ?? [],
                'usage_instructions' => $data['usage_instructions'] ?? null,
                'wizard_data' => $data,
            ]);

            $variants = $this->createVariants($product, $data);
            if ($data['sellable_retail'] && ! ($data['gift_only'] ?? false)) {
                $this->createRetailPrices($product, $variants, $data);
            }
            if ($data['sellable_dealer'] && ! ($data['gift_only'] ?? false)) {
                $this->createDealerPrices($product, $variants, $data);
            }
            if ($data['track_inventory']) {
                $this->createOpeningStock($product, $variants, $data, $actorId);
            }
            $product->update(['status' => 'active']);
            $this->audit->log(AuditLogger::ACTION_ACTIVATE, AuditLogger::MODULE_PRODUCT, $product, 'Completed Product wizard', [], ['status' => 'active'], ['variant_count' => count($variants)]);

            return $product;
        }, 3);
    }

    /** @param array<string, mixed> $data @return list<ProductVariant> */
    private function createVariants(Product $product, array $data): array
    {
        $rows = $data['has_variants'] ? $data['variants'] : [[
            'sku' => $data['sku'],
            'specifications' => [],
            'initial_stock' => $data['initial_stock'] ?? null,
        ]];
        $variants = [];
        foreach ($rows as $row) {
            $specifications = $row['specifications'] ?? [];
            $variant = $product->variants()->create([
                'sku' => $row['sku'],
                'variant_name' => $row['variant_name'] ?? ($specifications === [] ? 'Default' : implode(' / ', array_values($specifications))),
                'unit_id' => $data['unit_id'],
                'specifications' => $specifications === [] ? null : $specifications,
                'sellable_retail' => $data['sellable_retail'] && ! ($data['gift_only'] ?? false),
                'sellable_dealer' => $data['sellable_dealer'] && ! ($data['gift_only'] ?? false),
                'track_inventory' => $data['track_inventory'],
                'weight' => $data['weight'] ?? null,
                'length' => $data['length'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'status' => 'active',
            ]);
            if (! empty($row['image_id'])) {
                $image = $product->images()->whereKey($row['image_id'])->first();
                if ($image === null) {
                    throw ValidationException::withMessages(['data.variants' => 'Ảnh biến thể không thuộc sản phẩm này.']);
                }
                $image->update(['product_variant_id' => $variant->id]);
            }
            $variants[] = $variant;
        }

        return $variants;
    }

    /** @param list<ProductVariant> $variants @param array<string, mixed> $data */
    private function createRetailPrices(Product $product, array $variants, array $data): void
    {
        foreach ($variants as $index => $variant) {
            $row = $data['has_variants'] ? $data['variants'][$index] : [];
            $this->pricing->addInitialPrice($variant, 'retail', (string) ($row['retail_price_override'] ?? $data['retail_price']));
        }
    }

    /** @param list<ProductVariant> $variants @param array<string, mixed> $data */
    private function createDealerPrices(Product $product, array $variants, array $data): void
    {
        $variantsBySku = collect($variants)->keyBy('sku');
        foreach (collect($data['dealer_rules'])->groupBy('tier_id') as $tierId => $rules) {
            foreach ($rules as $rule) {
                $this->pricing->addInitialPrice(
                    $variantsBySku->get(Sku::normalize($rule['sku'])),
                    'dealer',
                    (string) $rule['unit_price'],
                    (int) $rule['min_quantity'],
                    (int) $tierId,
                );
            }
        }
    }

    /** @param list<ProductVariant> $variants @param array<string, mixed> $data */
    private function createOpeningStock(Product $product, array $variants, array $data, int $actorId): void
    {
        foreach ($variants as $index => $variant) {
            $quantity = $data['has_variants'] ? ($data['variants'][$index]['initial_stock'] ?? 0) : ($data['initial_stock'] ?? 0);
            if (bccomp((string) $quantity, '0', 3) <= 0) {
                continue;
            }
            $this->inventory->opening([
                'warehouse_id' => $data['warehouse_id'],
                'product_variant_id' => $variant->id,
                'quantity' => (string) $quantity,
                'operation_key' => (string) Str::uuid(),
                'reference_type' => 'product_wizard',
                'reference_id' => (string) $product->id,
                'reason_detail' => 'Opening stock from Product creation',
            ], $actorId);
        }
    }
}
