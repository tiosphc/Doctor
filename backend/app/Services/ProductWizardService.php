<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductWizardService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

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
                'default_low_stock_threshold' => $data['low_stock_threshold'] ?? null,
                'youtube_videos' => $data['youtube_videos'] ?? [],
                'usage_instructions' => $data['usage_instructions'] ?? null,
                'wizard_data' => $data,
            ]);

            $variants = $this->createVariants($product, $data);
            if ($data['sellable_retail']) {
                $this->createRetailPrices($product, $variants, $data);
            }
            if ($data['sellable_dealer']) {
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
                'variant_name' => $specifications === [] ? 'Default' : implode(' / ', array_values($specifications)),
                'unit_id' => $data['unit_id'],
                'specifications' => $specifications === [] ? null : $specifications,
                'sellable_retail' => $data['sellable_retail'],
                'sellable_dealer' => $data['sellable_dealer'],
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
        $list = PriceList::create([
            'code' => 'WIZR-'.$product->id,
            'name' => 'Retail - '.$product->product_code,
            'pricing_context' => 'retail',
            'scope_type' => 'all',
            'currency' => 'VND',
            'priority' => 0,
            'status' => 'active',
        ]);
        foreach ($variants as $index => $variant) {
            $row = $data['has_variants'] ? $data['variants'][$index] : [];
            $list->items()->create([
                'product_variant_id' => $variant->id,
                'unit_price' => $row['retail_price_override'] ?? $data['retail_price'],
                'minimum_quantity' => 1,
            ]);
            foreach ($data['retail_breaks'] ?? [] as $break) {
                $list->items()->create([
                    'product_variant_id' => $variant->id,
                    'unit_price' => $break['unit_price'],
                    'minimum_quantity' => $break['min_quantity'],
                ]);
            }
        }
    }

    /** @param list<ProductVariant> $variants @param array<string, mixed> $data */
    private function createDealerPrices(Product $product, array $variants, array $data): void
    {
        foreach (collect($data['dealer_rules'])->groupBy('tier_id') as $tierId => $rules) {
            $list = PriceList::create([
                'code' => 'WIZD-'.$product->id.'-'.$tierId,
                'name' => 'Dealer - '.$product->product_code.' - '.$tierId,
                'pricing_context' => 'dealer',
                'scope_type' => 'tier',
                'dealer_tier_id' => $tierId,
                'currency' => 'VND',
                'priority' => 0,
                'status' => 'active',
            ]);
            foreach ($variants as $variant) {
                foreach ($rules as $rule) {
                    $list->items()->create([
                        'product_variant_id' => $variant->id,
                        'unit_price' => $rule['unit_price'],
                        'minimum_quantity' => $rule['min_quantity'],
                    ]);
                }
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
