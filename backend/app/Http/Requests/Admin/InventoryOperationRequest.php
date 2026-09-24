<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryOperationRequest extends FormRequest
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
        $adjustment = $this->routeIs('admin.inventory.adjustments');
        $opening = $this->routeIs('admin.inventory.opening-stock');

        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'quantity' => ['required', 'string', $adjustment
                ? 'regex:/^-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/'
                : 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/'],
            'operation_key' => ['required', 'uuid'],
            'reason_code' => [$adjustment ? 'required' : 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'reason_detail' => [$adjustment || $opening ? 'required' : 'nullable', 'string', 'max:2000'],
            'reference_type' => ['nullable', 'required_with:reference_id', Rule::in(['MANUAL', 'STOCK_MOVEMENT'])],
            'reference_id' => ['nullable', 'string', 'max:100', 'required_with:reference_type'],
            'before_on_hand_quantity' => ['prohibited'],
            'after_on_hand_quantity' => ['prohibited'],
            'reserved_quantity' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('quantity') && is_numeric($this->input('quantity'))) {
            $this->merge(['quantity' => (string) $this->input('quantity')]);
        }
        if ($this->has('reason_code')) {
            $this->merge(['reason_code' => strtoupper(trim((string) $this->input('reason_code')))]);
        }
    }
}
