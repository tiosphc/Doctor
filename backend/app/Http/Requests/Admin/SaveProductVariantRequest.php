<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\Sku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductVariantRequest extends FormRequest
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
            'sku' => [$required, 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('product_variants', 'sku')->ignore($this->route('variant'))],
            'variant_name' => [$required, 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'unit_id' => [$required, 'integer', Rule::exists('units', 'id')->where('status', 'active')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->ignore($this->route('variant'))],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['string', 'max:255'],
            'sellable_retail' => ['sometimes', 'boolean'],
            'sellable_dealer' => ['sometimes', 'boolean'],
            'clinic_material' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'length' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'width' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'height' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('variant_name'))) {
            $this->merge(['variant_name' => trim($this->input('variant_name'))]);
        }
        if ($this->isMethod('post')) {
            $product = $this->route('product');
            if ($product instanceof Product) {
                if (! $this->has('sku') && $this->filled('variant_name')) {
                    $this->merge(['sku' => Sku::fromVariantName($product->base_sku ?: $product->product_code, (string) $this->input('variant_name'))]);
                }
                if (! $this->has('unit_id')) {
                    $this->merge(['unit_id' => $product->variants()->orderBy('id')->value('unit_id')]);
                }
            }
        }
        if ($this->has('sku')) {
            $this->merge(['sku' => Sku::normalize((string) $this->input('sku'))]);
        }
    }
}
