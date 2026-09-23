<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncDoctorServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer', 'distinct:strict', 'exists:services,id'],
        ];
    }
}
