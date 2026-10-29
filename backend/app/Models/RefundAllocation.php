<?php

namespace App\Models;

use Database\Factories\RefundAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundAllocation extends Model
{
    /** @use HasFactory<RefundAllocationFactory> */
    use HasFactory;

    protected $fillable = ['refund_id', 'payment_allocation_id', 'amount'];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
