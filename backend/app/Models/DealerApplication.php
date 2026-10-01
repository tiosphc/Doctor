<?php

namespace App\Models;

use Database\Factories\DealerApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'company_name', 'trading_name', 'contact_name', 'email', 'phone', 'tax_code', 'business_address_line1', 'business_address_line2', 'city', 'province', 'country', 'postal_code', 'business_type', 'estimated_monthly_purchase', 'note', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason'])]
class DealerApplication extends Model
{
    /** @use HasFactory<DealerApplicationFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedAccount(): HasOne
    {
        return $this->hasOne(DealerAccount::class, 'source_application_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'estimated_monthly_purchase' => 'decimal:2'];
    }
}
