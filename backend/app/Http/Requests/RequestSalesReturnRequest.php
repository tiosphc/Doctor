<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestSalesReturnRequest extends FormRequest
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
            'reason_code' => ['required', 'in:defective,damaged_in_delivery,wrong_product,wrong_specification,not_as_described,other'],
            'note' => ['required_if:reason_code,other', 'nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
