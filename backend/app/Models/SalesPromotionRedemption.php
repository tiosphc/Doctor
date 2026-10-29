<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPromotionRedemption extends Model
{
    protected $guarded = [];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(SalesPromotion::class, 'sales_promotion_id');
    }

    protected function casts(): array
    {
        return ['discount_value_snapshot' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'redeemed_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
