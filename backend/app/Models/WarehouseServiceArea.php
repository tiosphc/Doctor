<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseServiceArea extends Model
{
    public $timestamps = false;

    protected $fillable = ['warehouse_id', 'province_code', 'priority'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
