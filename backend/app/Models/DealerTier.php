<?php

namespace App\Models;

use Database\Factories\DealerTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerTier extends Model
{
    /** @use HasFactory<DealerTierFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'status'];
}
