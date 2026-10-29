<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPromotionGiftRule extends Model
{
    protected $guarded = [];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(SalesPromotion::class, 'sales_promotion_id');
    }

    public function buyProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'buy_product_id');
    }

    public function giftProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    public function buyVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'buy_variant_id');
    }

    public function giftVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'gift_variant_id');
    }

    protected function casts(): array
    {
        return ['minimum_buy_quantity' => 'decimal:3', 'gift_quantity' => 'decimal:3',
            'repeat_per_multiple' => 'boolean'];
    }
}
