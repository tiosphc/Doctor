<?php

namespace App\Models;

use Database\Factories\DealerWalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealerWallet extends Model
{
    /** @use HasFactory<DealerWalletFactory> */
    use HasFactory;

    protected $fillable = ['dealer_account_id', 'currency', 'balance'];

    public function dealerAccount(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DealerWalletTransaction::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(DealerWalletDeposit::class);
    }

    protected function casts(): array
    {
        return ['balance' => 'decimal:2'];
    }
}
