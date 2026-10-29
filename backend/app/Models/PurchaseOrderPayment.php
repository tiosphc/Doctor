<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderPayment extends Model
{
    protected $fillable = ['purchase_order_id', 'operation_key', 'amount', 'payment_method', 'paid_at', 'external_reference', 'note', 'recorded_by'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }
}
