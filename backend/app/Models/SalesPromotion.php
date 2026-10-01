<?php

namespace App\Models;

use Database\Factories\SalesPromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesPromotion extends Model
{
    /** @use HasFactory<SalesPromotionFactory> */
    use HasFactory;

    protected $guarded = [];

    public function targets(): HasMany
    {
        return $this->hasMany(SalesPromotionTarget::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(SalesPromotionRedemption::class);
    }

    public function giftRule(): HasOne
    {
        return $this->hasOne(SalesPromotionGiftRule::class);
    }

    public function dealerTiers(): BelongsToMany
    {
        return $this->belongsToMany(DealerTier::class, 'sales_promotion_dealer_tiers');
    }

    public function giftItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'source_promotion_id');
    }

    protected function casts(): array
    {
        return ['discount_value' => 'decimal:2', 'max_discount_amount' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
