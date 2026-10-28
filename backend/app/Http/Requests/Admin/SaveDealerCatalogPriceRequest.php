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

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->routeIs('admin.web.*') ? [
            'unit_price.required' => 'Vui lòng nhập giá đại lý.',
            'unit_price.numeric' => 'Giá đại lý phải là số.',
            'unit_price.gt' => 'Giá đại lý phải lớn hơn 0.',
            'unit_price.decimal' => 'Giá đại lý có tối đa 2 chữ số thập phân.',
            'minimum_quantity.required' => 'Vui lòng nhập MOQ.',
            'minimum_quantity.integer' => 'MOQ phải là số nguyên.',
            'minimum_quantity.min' => 'MOQ phải từ 1 trở lên.',
        ] : [];
    }
}
