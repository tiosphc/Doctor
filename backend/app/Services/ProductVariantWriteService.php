<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class ProductVariantWriteService
{
    /** @param array<string, mixed> $data */
    public function create(Product $product, array $data): ProductVariant
    {
        $firstVariant = $product->variants()->orderBy('id')->first();
        if ($firstVariant !== null) {
            foreach (['sellable_retail', 'sellable_dealer', 'clinic_material'] as $field) {
                $data[$field] ??= $firstVariant->{$field};
            }
        }
        foreach (['track_inventory', 'track_batch', 'track_expiry'] as $field) {
            $data[$field] ??= $product->{$field};
        }

        return $product->variants()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return bool Whether the update was accepted
     */
    public function update(Product $product, ProductVariant $variant, array $data): bool
    {
        abort_unless($variant->product_id === $product->id, 404);

        return DB::transaction(function () use ($variant, $data): bool {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            if ((isset($data['sku']) && $data['sku'] !== $locked->sku
                || isset($data['unit_id']) && (int) $data['unit_id'] !== $locked->unit_id
                || array_key_exists('track_inventory', $data) && (bool) $data['track_inventory'] !== $locked->track_inventory)
                && (DB::table('inventory_balances')->where('product_variant_id', $locked->id)->exists()
                    || DB::table('stock_movements')->where('product_variant_id', $locked->id)->exists())) {
                return false;
            }
            $locked->update($data);

            return true;
        });
    }
}
