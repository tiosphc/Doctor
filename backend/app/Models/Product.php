<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['product_code', 'name', 'slug', 'description', 'product_category_id', 'brand_id', 'status', 'track_inventory', 'track_batch', 'track_expiry', 'default_low_stock_threshold', 'base_sku', 'wizard_key', 'wizard_owner_user_id', 'wizard_data', 'youtube_videos', 'usage_instructions'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    protected function casts(): array
    {
        return ['track_inventory' => 'boolean', 'track_batch' => 'boolean', 'track_expiry' => 'boolean', 'default_low_stock_threshold' => 'decimal:3', 'wizard_data' => 'array', 'youtube_videos' => 'array'];
    }
}
