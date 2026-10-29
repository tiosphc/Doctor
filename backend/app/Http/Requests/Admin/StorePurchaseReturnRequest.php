<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'distinct', 'exists:goods_receipt_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
