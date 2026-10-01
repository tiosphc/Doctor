<?php

namespace App\Models;

use Database\Factories\DealerWalletTopUpRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DealerWalletTopUpRequest extends Model
{
    public const STATUS_INITIATING = 'initiating';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @use HasFactory<DealerWalletTopUpRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    public function dealerAccount(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class);
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(DealerWalletDeposit::class, 'dealer_wallet_top_up_request_id');
    }

    public function getTopUpCodeAttribute(): string
    {
        return 'TUP'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'completed_at' => 'datetime', 'expires_at' => 'datetime',
            'provider_checked_at' => 'datetime', 'expired_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
