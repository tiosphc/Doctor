<?php

namespace App\Support;

use App\Models\Product;

class BladeProductWizardPayload
{
    /** @return array<string, mixed> */
    public static function forForm(Product $product): array
    {
        $saved = $product->wizard_data ?? [];
        $data = array_replace([
            'name' => $product->name ?? '', 'sku' => $product->base_sku ?? '',
            'product_category_id' => $product->product_category_id, 'brand_id' => $product->brand_id,
            'unit_id' => null, 'sellable_retail' => true, 'sellable_dealer' => false,
            'can_be_gift' => false, 'gift_only' => false, 'description' => '', 'youtube_videos' => [],
            'has_variants' => false, 'attributes' => [], 'variants' => [],
            'retail_price' => '', 'retail_breaks' => [], 'dealer_rules' => [],
            'track_inventory' => true, 'warehouse_id' => null, 'initial_stock' => null,
            'low_stock_threshold' => null, 'weight' => null, 'length' => null, 'width' => null,
            'height' => null, 'usage_instructions' => '',
        ], is_array($saved) ? $saved : []);

        $skuChanges = [];
        $variants = [];
        foreach (is_array($data['variants']) ? $data['variants'] : [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $name = trim((string) ($variant['variant_name'] ?? ''));
            if ($name === '') {
                $name = implode(' / ', array_values(is_array($variant['specifications'] ?? null) ? $variant['specifications'] : []));
            }
            $sku = Sku::fromVariantName((string) $data['sku'], $name);
            $skuChanges[(string) ($variant['sku'] ?? '')] = $sku;
            $variants[] = [
                'variant_name' => $name,
                'sku' => $sku,
                'image_id' => $variant['image_id'] ?? null,
                'retail_price_override' => $variant['retail_price_override'] ?? null,
                'initial_stock' => $variant['initial_stock'] ?? null,
            ];
        }
        $data['variants'] = $variants;
        $data['attributes'] = [];
        $data['retail_breaks'] = [];
        if ($data['has_variants']) {
            $data['initial_stock'] = null;
        }
        if (! $data['sellable_retail'] || $data['gift_only']) {
            $data['retail_price'] = null;
        }
        $data['dealer_rules'] = array_values(array_map(function (array $rule) use ($data, $skuChanges, $variants): array {
            $sku = $skuChanges[(string) ($rule['sku'] ?? '')] ?? (string) ($rule['sku'] ?? '');
            $index = array_search($sku, array_column($variants, 'sku'), true);

            return [
                ...$rule,
                'variant_index' => $data['has_variants'] ? ($index === false ? '' : $index) : 0,
            ];
        }, array_filter(is_array($data['dealer_rules']) ? $data['dealer_rules'] : [], 'is_array')));

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalize(array $data): array
    {
        foreach (['sellable_retail', 'sellable_dealer', 'can_be_gift', 'gift_only', 'has_variants', 'track_inventory'] as $flag) {
            $data[$flag] = in_array($data[$flag] ?? false, [true, 1, '1', 'on'], true);
        }
        $data['name'] = trim((string) ($data['name'] ?? ''));
        $data['sku'] = Sku::normalize((string) ($data['sku'] ?? ''));
        foreach (['brand_id', 'warehouse_id', 'retail_price', 'initial_stock', 'low_stock_threshold', 'weight', 'length', 'width', 'height', 'description', 'usage_instructions'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }
        $data['youtube_videos'] = array_values(array_filter(
            is_array($data['youtube_videos'] ?? null) ? $data['youtube_videos'] : [],
            fn ($url): bool => filled($url),
        ));
        $data['attributes'] = [];
        $data['retail_breaks'] = [];

        $variants = [];
        if ($data['has_variants'] && is_array($data['variants'] ?? null)) {
            foreach (array_values($data['variants']) as $variant) {
                if (! is_array($variant)) {
                    $variants[] = $variant;

                    continue;
                }
                $name = trim((string) ($variant['variant_name'] ?? ''));
                $variants[] = [
                    'variant_name' => $name,
                    'sku' => Sku::fromVariantName($data['sku'], $name),
                    'specifications' => [],
                    'image_id' => ($variant['image_id'] ?? '') === '' ? null : ($variant['image_id'] ?? null),
                    'retail_price_override' => ! $data['sellable_retail'] || $data['gift_only'] || ($variant['retail_price_override'] ?? '') === '' ? null : $variant['retail_price_override'],
                    'initial_stock' => ($variant['initial_stock'] ?? '') === '' ? null : $variant['initial_stock'],
                ];
            }
        }
        $data['variants'] = $data['has_variants'] ? $variants : [];

        if (! $data['sellable_dealer'] || $data['gift_only']) {
            $data['dealer_rules'] = [];
        } elseif (is_array($data['dealer_rules'] ?? null)) {
            $rules = [];
            foreach (array_values($data['dealer_rules']) as $rule) {
                if (! is_array($rule)) {
                    $rules[] = $rule;

                    continue;
                }
                $index = filter_var($rule['variant_index'] ?? null, FILTER_VALIDATE_INT);
                $rules[] = [
                    'tier_id' => ($rule['tier_id'] ?? '') === '' ? null : $rule['tier_id'],
                    'sku' => $data['has_variants'] ? (is_int($index) ? ($variants[$index]['sku'] ?? '') : '') : $data['sku'],
                    'min_quantity' => $rule['min_quantity'] ?? null,
                    'unit_price' => $rule['unit_price'] ?? null,
                ];
            }
            $data['dealer_rules'] = $rules;
        } else {
            $data['dealer_rules'] = $data['dealer_rules'] ?? [];
        }

        return $data;
    }
}
