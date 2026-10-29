<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'symbol', 'decimal_precision', 'status'];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    protected function casts(): array
    {
        return ['decimal_precision' => 'integer'];
    }
}
