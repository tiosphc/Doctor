<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRetailPriceItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'product_variant_id' => [$required, 'integer', Rule::exists('product_variants', 'id')],
            'unit_price' => [$required, 'numeric', 'min:0', 'decimal:0,2'],
            'minimum_quantity' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
