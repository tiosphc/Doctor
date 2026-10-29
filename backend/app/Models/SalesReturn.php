<?php

namespace App\Models;

use Database\Factories\SalesReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    /** @use HasFactory<SalesReturnFactory> */
    use HasFactory;

    protected $fillable = ['return_code', 'sales_order_id', 'warehouse_id', 'status', 'request_source', 'requested_by_user_id', 'reason', 'reason_code', 'note', 'approved_by_user_id', 'approved_at', 'rejected_by_user_id', 'rejected_at', 'rejection_reason', 'received_by_user_id', 'received_at', 'processed_by_user_id', 'operation_key', 'request_fingerprint', 'processing_operation_key', 'processing_fingerprint', 'completed_at'];

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'rejected_at' => 'datetime', 'received_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
