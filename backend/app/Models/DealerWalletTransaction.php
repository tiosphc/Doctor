<?php

namespace App\Models;

use Database\Factories\DealerWalletTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerWalletTransaction extends Model
{
    /** @use HasFactory<DealerWalletTransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(DealerWallet::class, 'dealer_wallet_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(DealerWalletDeposit::class, 'dealer_wallet_deposit_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_before' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }
}
