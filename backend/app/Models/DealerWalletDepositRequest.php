<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerWalletDepositRequest extends Model
{
    protected $guarded = [];

    public function dealerAccount(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(DealerWallet::class, 'dealer_wallet_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(DealerWalletTransaction::class, 'dealer_wallet_transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }
}
