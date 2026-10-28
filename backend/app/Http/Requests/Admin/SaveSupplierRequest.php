<?php

namespace App\Http\Requests\Admin;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $supplier = $this->route('supplier');
        $this->merge(['code' => strtoupper(trim((string) $this->input('code', $supplier instanceof Supplier ? $supplier->code : '')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $existing = $supplier instanceof Supplier ? $supplier : null;

        return [
            'code' => [$existing ? 'sometimes' : 'required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('suppliers', 'code')->ignore($existing?->id)],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
