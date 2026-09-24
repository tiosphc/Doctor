<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrderItem extends Model
{
    protected $fillable = ['product_variant_id', 'product_code_snapshot', 'product_name_snapshot', 'sku_snapshot', 'variant_name_snapshot', 'unit_code_snapshot', 'unit_name_snapshot', 'quantity', 'pricing_context_snapshot', 'price_list_id', 'price_list_item_id', 'price_resolution_fingerprint', 'unit_price_snapshot', 'base_amount', 'discount_amount', 'tax_amount', 'line_total'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(InventoryReservation::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price_snapshot' => 'decimal:2', 'base_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
