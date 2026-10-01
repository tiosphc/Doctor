<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerShippingAddress extends Model
{
    protected $fillable = ['dealer_account_id', 'recipient_name', 'recipient_phone', 'address_line',
        'province_code', 'ward_code', 'postal_code', 'district_legacy', 'is_default'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(AdministrativeProvince::class, 'province_code', 'code');
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(AdministrativeWard::class, 'ward_code', 'code');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
