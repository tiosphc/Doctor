<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'address_line1', 'address_line2', 'city', 'province', 'country', 'postal_code', 'timezone', 'type', 'status', 'is_default_sales', 'is_default_clinic', 'dealer_price_adjustment_percent'];

    protected $hidden = ['active_sales_default', 'active_clinic_default', 'dealer_price_adjustment_percent'];

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    protected function casts(): array
    {
        return ['is_default_sales' => 'boolean', 'is_default_clinic' => 'boolean', 'dealer_price_adjustment_percent' => 'decimal:2'];
    }
}
