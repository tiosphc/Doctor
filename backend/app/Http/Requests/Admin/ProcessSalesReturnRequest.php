<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProcessSalesReturnRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.return_item_id' => ['required', 'integer'],
            'items.*.restock_quantity' => ['required', 'integer', 'min:0'],
            'items.*.non_restock_reason_code' => ['nullable', 'in:damaged,used,expired,damaged_packaging,missing_parts,unsellable,destroyed,other'],
            'items.*.non_restock_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
