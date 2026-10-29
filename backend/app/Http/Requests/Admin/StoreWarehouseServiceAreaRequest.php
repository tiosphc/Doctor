<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseServiceAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'province_code' => ['required', 'string', Rule::exists('administrative_provinces', 'code'),
                Rule::unique('warehouse_service_areas', 'province_code')->where('warehouse_id', $this->input('warehouse_id'))],
            'priority' => ['required', 'integer', 'between:0,65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('province_code'))) {
            $this->merge(['province_code' => trim($this->input('province_code'))]);
        }
    }
}
