<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseServiceAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['prohibited'],
            'province_code' => ['prohibited'],
            'priority' => ['required', 'integer', 'between:0,65535'],
        ];
    }
}
