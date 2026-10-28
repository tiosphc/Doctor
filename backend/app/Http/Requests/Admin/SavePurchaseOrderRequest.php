<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->route('purchaseOrder') === null ? 'required' : 'sometimes';

        return [
            'supplier_id' => [$required, 'integer', 'exists:suppliers,id'],
            'warehouse_id' => [$required, 'integer', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_order_reference' => ['nullable', 'string', 'max:100'],
            'items' => [$required, 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?$/'],
        ];
    }
}
