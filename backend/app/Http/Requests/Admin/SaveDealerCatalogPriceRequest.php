<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveDealerCatalogPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'minimum_quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
