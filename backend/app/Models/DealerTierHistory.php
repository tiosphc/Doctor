<?php

namespace App\Models;

use Database\Factories\DealerTierHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dealer_account_id', 'previous_tier_id', 'new_tier_id', 'source', 'reason', 'operation_key', 'effective_at', 'expires_at', 'changed_by', 'net_revenue_snapshot', 'policy_version', 'evaluation_period', 'revenue_period_start', 'revenue_period_end', 'evaluated_at'])]
class DealerTierHistory extends Model
{
    /** @use HasFactory<DealerTierHistoryFactory> */
    use HasFactory;

    public const SOURCE_INITIAL = 'initial_assignment';

    public const SOURCE_MIGRATION = 'migration';

    public const SOURCE_MANUAL = 'manual_change';

    public const SOURCE_AUTOMATIC_REVENUE = 'automatic_revenue';

    public const SOURCE_AUTOMATIC_UPGRADE = 'automatic_upgrade';

    public const SOURCE_AUTOMATIC_MONTHLY = 'automatic_monthly_evaluation';

    public function previousTier(): BelongsTo
    {
        return $this->belongsTo(DealerTier::class, 'previous_tier_id');
    }

    public function newTier(): BelongsTo
    {
        return $this->belongsTo(DealerTier::class, 'new_tier_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'expires_at' => 'datetime', 'net_revenue_snapshot' => 'decimal:2', 'policy_version' => 'integer',
            'revenue_period_start' => 'date', 'revenue_period_end' => 'date', 'evaluated_at' => 'datetime'];
    }
}
