<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($this->route('product'))],
            'description' => ['nullable', 'string', 'max:20000'],
            'product_category_id' => [$required, 'integer', Rule::exists('product_categories', 'id')->where('status', 'active')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('status', 'active')],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'inactive'])],
            'track_inventory' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'default_low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'default_unit_id' => $this->isMethod('post') ? ['required', 'integer', Rule::exists('units', 'id')->where('status', 'active')] : ['prohibited'],
        ];
    }
}
