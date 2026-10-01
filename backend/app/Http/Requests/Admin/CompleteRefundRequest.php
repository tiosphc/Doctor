<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRefundRequest extends FormRequest
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
            'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/'],
            'refund_method' => ['required', 'in:cash,bank_transfer,other_manual,dealer_wallet'],
            'reason' => ['required', 'in:return,order_cancel,price_adjustment,service_recovery,other'],
            'return_id' => ['required_if:reason,return', 'nullable', 'integer', 'exists:sales_returns,id'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'operation_key' => ['required', 'uuid'],
        ];
    }
}
