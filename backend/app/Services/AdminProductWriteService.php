<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminProductWriteService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $variants
     */
    public function create(array $data, ?array $variants = null): Product
    {
        $data['status'] ??= 'active';
        $data['track_inventory'] ??= true;
        $unitId = $data['default_unit_id'];
        unset($data['default_unit_id']);

        return DB::transaction(function () use ($data, $unitId, $variants): Product {
            $product = Product::create([...$data, 'product_code' => 'TMP-'.Str::uuid()]);
            $product->update(['product_code' => 'PRD'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($variants ?? [[
                'sku' => $product->product_code.'-DEFAULT',
                'variant_name' => 'Default',
                'unit_id' => $unitId,
                'track_inventory' => $data['track_inventory'],
                'status' => 'active',
            ]] as $variant) {
                $product->variants()->create($variant);
            }

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product;
    }
}
