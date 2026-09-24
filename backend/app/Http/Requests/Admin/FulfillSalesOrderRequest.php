<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FulfillSalesOrderRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'string', 'regex:/^[1-9][0-9]{0,14}(?:\.[0-9]{1,3})?$/'],
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
            if (is_array($item) && is_numeric($item['quantity'] ?? null)) {
                $items[$index]['quantity'] = (string) $item['quantity'];
            }
        }
        $this->merge(['items' => $items]);
    }
}
