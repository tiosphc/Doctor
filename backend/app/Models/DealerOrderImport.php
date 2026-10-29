<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealerOrderImport extends Model
{
    protected $fillable = ['dealer_account_id', 'warehouse_id', 'uploaded_by', 'confirmed_by', 'original_filename', 'file_hash',
        'file_size', 'import_mode', 'status', 'row_count', 'order_count', 'valid_order_count',
        'invalid_order_count', 'preview_fingerprint', 'confirm_operation_key',
        'confirm_payload_fingerprint', 'preview_summary', 'confirmed_at'];

    public function rows(): HasMany
    {
        return $this->hasMany(DealerOrderImportRow::class)->orderBy('sheet_row_number');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(DealerOrderImportGroup::class)->orderBy('id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return ['preview_summary' => 'array', 'confirmed_at' => 'datetime'];
    }
}
