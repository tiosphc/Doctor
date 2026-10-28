<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveRetailCatalogPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->routeIs('admin.web.*') ? [
            'unit_price.required' => 'Vui lòng nhập giá Retail.',
            'unit_price.numeric' => 'Giá Retail phải là số.',
            'unit_price.min' => 'Giá Retail không được âm.',
            'unit_price.decimal' => 'Giá Retail có tối đa 2 chữ số thập phân.',
        ] : [];
    }
}
