<?php

namespace App\Models;

use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = ['refund_code', 'sales_order_id', 'sales_return_id', 'currency', 'amount', 'refund_method', 'status', 'reason_code', 'note', 'external_reference', 'external_reference_normalized', 'processed_by_user_id', 'operation_key', 'request_fingerprint', 'completed_at'];

    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'completed_at' => 'datetime'];
    }
}
