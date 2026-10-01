<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesVoucher extends Model
{
    protected $guarded = [];

    public function redemptions(): HasMany
    {
        return $this->hasMany(SalesVoucherRedemption::class);
    }

    protected function casts(): array
    {
        return ['discount_value' => 'decimal:2', 'max_discount_amount' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
