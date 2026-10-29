<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerOrderImportGroup extends Model
{
    protected $fillable = ['external_reference', 'external_reference_normalized', 'status',
        'review_fingerprint', 'preview', 'sales_order_id', 'error_code'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    protected function casts(): array
    {
        return ['preview' => 'array'];
    }
}
