<?php

namespace App\Models;

use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'user_id', 'type', 'value', 'source', 'source_id', 'status', 'expires_at', 'used_at'])]
class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';

    public const SOURCE_REVIEW_REWARD = 'review_reward';

    public const SOURCE_LOYALTY_MILESTONE = 'loyalty_milestone';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [self::SOURCE_REVIEW_REWARD, self::SOURCE_LOYALTY_MILESTONE, self::SOURCE_ADMIN];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_USED, self::STATUS_EXPIRED, self::STATUS_REVOKED];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usedAppointment(): HasOne
    {
        return $this->hasOne(Appointment::class)
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->latestOfMany();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isGeneralAdminVoucher(): bool
    {
        return $this->source === self::SOURCE_ADMIN && $this->user_id === null;
    }

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
