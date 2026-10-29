<?php

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'product_variant_id', 'path', 'alt_text', 'sort_order', 'is_primary'];

    protected $appends = ['url'];

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => url(Storage::disk('public')->url($this->path)));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_primary' => 'boolean'];
    }
}
