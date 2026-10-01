<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteSalesReturnRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'operation_key' => ['required', 'uuid'],
            'processing_mode' => ['sometimes', 'in:immediate,pending_inspection'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.restock_quantity' => ['required_unless:processing_mode,pending_inspection', 'nullable', 'integer', 'min:0'],
            'items.*.non_restock_reason_code' => ['nullable', 'in:damaged,used,expired,damaged_packaging,missing_parts,unsellable,destroyed,other'],
            'items.*.non_restock_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
