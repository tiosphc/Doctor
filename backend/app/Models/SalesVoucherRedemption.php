<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesVoucherRedemption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2', 'redeemed_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
