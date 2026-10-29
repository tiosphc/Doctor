<?php

namespace App\Models;

use Database\Factories\SalesReturnItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    /** @use HasFactory<SalesReturnItemFactory> */
    use HasFactory;

    protected $fillable = ['sales_order_item_id', 'quantity', 'restock_quantity', 'non_restock_quantity', 'non_restock_reason_code', 'non_restock_note', 'unit_value_snapshot', 'return_value_snapshot'];

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'restock_quantity' => 'decimal:3', 'non_restock_quantity' => 'decimal:3', 'unit_value_snapshot' => 'decimal:2', 'return_value_snapshot' => 'decimal:2'];
    }
}
