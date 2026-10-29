<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    protected $fillable = ['purchase_return_id', 'goods_receipt_item_id', 'quantity', 'stock_movement_id'];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
