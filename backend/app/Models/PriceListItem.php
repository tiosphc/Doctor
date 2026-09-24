<?php

namespace App\Models;

use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['price_list_id', 'product_variant_id', 'unit_price', 'minimum_quantity', 'status', 'effective_from', 'effective_to'];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'minimum_quantity' => 'decimal:3', 'effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }
}
