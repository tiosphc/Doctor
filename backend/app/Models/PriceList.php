<?php

namespace App\Models;

use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'pricing_context', 'scope_type', 'currency', 'effective_from', 'effective_to', 'priority', 'status', 'dealer_tier_id'];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'priority' => 'integer'];
    }
}
