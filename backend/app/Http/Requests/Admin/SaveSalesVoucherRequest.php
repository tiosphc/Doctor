<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSalesVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sales_scope' => ['prohibited'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => ['required', 'numeric', 'gt:0', 'decimal:0,2',
                Rule::when($this->input('discount_type') === 'percentage', ['lte:100'])],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'total_usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_buyer_usage_limit' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('discount_type') === 'fixed_amount') {
            $this->merge(['max_discount_amount' => null]);
        }
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }
    }
}
