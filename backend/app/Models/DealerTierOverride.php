<?php

namespace App\Models;

use Database\Factories\DealerTierOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dealer_account_id', 'tier_id', 'starts_at', 'ends_at', 'reason', 'status', 'created_by', 'cancelled_by', 'cancelled_at'])]
class DealerTierOverride extends Model
{
    /** @use HasFactory<DealerTierOverrideFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public function tier(): BelongsTo
    {
        return $this->belongsTo(DealerTier::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class, 'dealer_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
