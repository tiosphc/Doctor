<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'sku', 'variant_name', 'unit_id', 'barcode', 'specifications', 'sellable_retail', 'sellable_dealer', 'clinic_material', 'track_inventory', 'track_batch', 'track_expiry', 'weight', 'length', 'width', 'height', 'status'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function priceItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    protected function casts(): array
    {
        return ['specifications' => 'array', 'sellable_retail' => 'boolean', 'sellable_dealer' => 'boolean', 'clinic_material' => 'boolean', 'track_inventory' => 'boolean', 'track_batch' => 'boolean', 'track_expiry' => 'boolean', 'weight' => 'decimal:3', 'length' => 'decimal:3', 'width' => 'decimal:3', 'height' => 'decimal:3'];
    }
}
