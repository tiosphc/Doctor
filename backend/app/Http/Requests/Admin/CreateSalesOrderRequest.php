<?php

namespace App\Http\Requests\Admin;

use App\Support\Sku;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateSalesOrderRequest extends FormRequest
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
        return [
            'operation_key' => ['required', 'uuid'],
            'confirm' => ['sometimes', 'boolean'],
            'confirm_operation_key' => ['required_if:confirm,true', 'nullable', 'uuid'],
            'sales_channel' => ['required', Rule::in(['retail', 'dealer'])],
            'buyer_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'currency' => ['required', Rule::in(['VND'])],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:50'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'shipping_address_line1' => ['required', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:255'],
            'shipping_district' => ['nullable', 'string', 'max:255'],
            'shipping_province' => ['required', 'string', 'max:255'],
            'shipping_country' => ['required', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:30'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', Rule::in(['cod', 'bank_transfer'])],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['prohibited'],
            'items.*.price_list_id' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'price_list_id' => ['prohibited'],
            'order_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'fulfillment_status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (is_string($item['sku'] ?? null)) {
                $items[$index]['sku'] = Sku::normalize($item['sku']);
            }
            if (is_numeric($item['quantity'] ?? null)) {
                $items[$index]['quantity'] = (string) $item['quantity'];
            }
        }
        $this->merge(['items' => $items]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seen = [];
            foreach (is_array($this->input('items')) ? $this->input('items') : [] as $index => $item) {
                if (! is_array($item) || ! is_string($item['sku'] ?? null)) {
                    continue;
                }
                if (isset($seen[$item['sku']])) {
                    $validator->errors()->add("items.$index.sku", 'Duplicate SKU lines are not allowed.');
                }
                $seen[$item['sku']] = true;
            }
        });
    }
}
