<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_code',
    'user_id',
    'name',
    'primary_email',
    'normalized_email',
    'primary_phone',
    'normalized_phone',
    'verified_email_at',
    'verified_phone_at',
    'status',
    'source',
    'merged_into_customer_id',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MERGED = 'merged';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_MERGED];

    public const SOURCE_REGISTERED = 'registered';

    public const SOURCE_GUEST_BOOKING = 'guest_booking';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_LEGACY_BACKFILL = 'legacy_backfill';

    public const SOURCE_IMPORT = 'import';

    public const SOURCES = [
        self::SOURCE_REGISTERED,
        self::SOURCE_GUEST_BOOKING,
        self::SOURCE_ADMIN,
        self::SOURCE_LEGACY_BACKFILL,
        self::SOURCE_IMPORT,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_customer_id');
    }

    public function mergedSources(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_customer_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified_email_at' => 'datetime',
            'verified_phone_at' => 'datetime',
        ];
    }
}
