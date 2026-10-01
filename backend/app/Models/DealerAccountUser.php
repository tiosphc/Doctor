<?php

namespace App\Models;

use Database\Factories\DealerAccountUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dealer_account_id', 'user_id', 'membership_role', 'status', 'invited_at', 'activated_at'])]
class DealerAccountUser extends Model
{
    /** @use HasFactory<DealerAccountUserFactory> */
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    public function account(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class, 'dealer_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'activated_at' => 'datetime'];
    }
}
