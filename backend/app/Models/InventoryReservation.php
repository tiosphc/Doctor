<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservation extends Model
{
    protected $fillable = ['sales_order_id', 'sales_order_item_id', 'warehouse_id', 'product_variant_id', 'original_quantity', 'consumed_quantity', 'released_quantity', 'status', 'expires_at', 'operation_key'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    protected function casts(): array
    {
        return ['original_quantity' => 'decimal:3', 'consumed_quantity' => 'decimal:3', 'released_quantity' => 'decimal:3', 'expires_at' => 'datetime'];
    }
}
