<?php

namespace App\Models;

use Database\Factories\DealerWalletDepositFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DealerWalletDeposit extends Model
{
    /** @use HasFactory<DealerWalletDepositFactory> */
    use HasFactory;

    protected $guarded = [];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(DealerWallet::class, 'dealer_wallet_id');
    }

    public function dealerAccount(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(DealerWalletTransaction::class, 'dealer_wallet_deposit_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'completed_at' => 'datetime'];
    }
}
