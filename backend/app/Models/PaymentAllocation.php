<?php

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected $fillable = ['payment_id', 'sales_order_id', 'allocated_amount'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }
}
