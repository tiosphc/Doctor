<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealerOrderImportRow extends Model
{
    protected $fillable = ['sheet_row_number', 'external_reference', 'external_reference_normalized',
        'sku_input', 'product_variant_id', 'quantity', 'promotion_code', 'recipient', 'validation_errors'];

    protected function casts(): array
    {
        return ['recipient' => 'array', 'validation_errors' => 'array'];
    }
}
