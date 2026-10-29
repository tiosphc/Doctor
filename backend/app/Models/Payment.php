<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = ['payment_code', 'payment_context', 'dealer_account_id', 'payer_user_id', 'currency', 'amount', 'payment_method', 'status', 'external_reference', 'external_reference_normalized', 'note', 'recorded_by_user_id', 'operation_key', 'request_fingerprint', 'settled_at'];

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_at' => 'datetime'];
    }
}
