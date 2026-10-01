<?php

namespace App\Models;

use Database\Factories\DealerAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'legal_name', 'trading_name', 'contact_name', 'email', 'phone', 'tax_code', 'billing_address_line1', 'billing_address_line2', 'city', 'province', 'country', 'postal_code', 'status', 'source_application_id', 'created_by', 'updated_by', 'activated_at', 'suspended_at', 'inactivated_at', 'current_tier_id', 'tier_assigned_at', 'tier_expires_at'])]
class DealerAccount extends Model
{
    /** @use HasFactory<DealerAccountFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    public function application(): BelongsTo
    {
        return $this->belongsTo(DealerApplication::class, 'source_application_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(DealerAccountUser::class);
    }

    public function currentTier(): BelongsTo
    {
        return $this->belongsTo(DealerTier::class, 'current_tier_id');
    }

    public function tierHistories(): HasMany
    {
        return $this->hasMany(DealerTierHistory::class);
    }

    public function tierOverrides(): HasMany
    {
        return $this->hasMany(DealerTierOverride::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(DealerWallet::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'suspended_at' => 'datetime', 'inactivated_at' => 'datetime', 'tier_assigned_at' => 'datetime', 'tier_expires_at' => 'datetime'];
    }
}
