<?php

namespace App\Models;

use Database\Factories\DealerTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerTier extends Model
{
    /** @use HasFactory<DealerTierFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'status', 'sort_order', 'description', 'is_default_initial', 'revenue_threshold'];

    protected function casts(): array
    {
        return ['is_default_initial' => 'boolean', 'sort_order' => 'integer', 'revenue_threshold' => 'decimal:2'];
    }
}
