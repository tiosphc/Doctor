<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_variant_id', 'sku_snapshot', 'name_snapshot', 'ordered_quantity', 'received_quantity', 'unit_price', 'line_total'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    protected function casts(): array
    {
        return ['ordered_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
