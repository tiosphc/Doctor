<?php

namespace App\Http\Resources;

use App\Services\RetailPricingService;
use App\Services\SalesPromotionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pricing = app(RetailPricingService::class);
        $promotions = app(SalesPromotionService::class);
        $discount = $this->retail_discount_model;
        $variants = $this->variants->map(fn ($variant): array => [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'variant_name' => $variant->variant_name,
            'track_inventory' => $variant->track_inventory,
            'unit' => $variant->unit?->name,
            'unit_symbol' => $variant->unit?->symbol,
            'unit_precision' => $variant->unit?->decimal_precision,
            'specifications' => collect($variant->specifications ?? [])
                ->filter(fn (mixed $value, int|string $key): bool => is_string($key)
                    && is_string($value)
                    && ! preg_match('/cost|price|dealer|tier|stock|inventory|margin|warehouse/i', $key))
                ->all(),
            'retail_price' => (function () use ($variant, $pricing, $promotions, $discount): array {
                $price = array_intersect_key($pricing->resolve($variant), array_flip(['unit_price', 'currency', 'pricing_context']));
                $price['discounted_unit_price'] = $discount !== null
                    && bccomp($price['unit_price'], $discount->minimum_order_amount, 2) >= 0
                    ? $promotions->discountedUnitPrice($price['unit_price'], $discount) : null;

                return $price;
            })(),
        ]);

        return [
            'id' => $this->id,
            'product_code' => $this->product_code,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'gift_promotions' => $this->gift_promotions ?? [],
            'retail_promotions' => $this->retail_promotions ?? [],
            'retail_discount_promotion' => $this->retail_discount_promotion ?? null,
            'youtube_videos' => $this->youtube_videos ?? [],
            'usage_instructions' => $this->usage_instructions,
            'category' => $this->category?->only(['id', 'code', 'name']),
            'brand' => $this->brand?->only(['id', 'code', 'name']),
            'images' => $this->images->map(fn ($image): array => [
                'id' => $image->id,
                'url' => url(Storage::disk('public')->url($image->path)),
                'alt_text' => $image->alt_text,
                'product_variant_id' => $image->product_variant_id,
                'is_primary' => $image->is_primary,
            ]),
            'variants' => $variants,
            'retail_price' => $variants->first()['retail_price'],
        ];
    }
}
