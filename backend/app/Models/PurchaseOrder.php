<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = ['code', 'supplier_id', 'warehouse_id', 'status', 'currency', 'total_amount', 'note', 'expected_delivery_date', 'supplier_order_reference', 'payment_status', 'paid_amount', 'created_by', 'issued_by', 'issued_at', 'cancelled_at'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'expected_delivery_date' => 'date:Y-m-d', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
