<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
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
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'max:19'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'other_manual'])],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'payment_status' => ['prohibited'],
            'status' => ['prohibited'],
            'currency' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'dealer_account_id' => ['prohibited'],
            'payment_context' => ['prohibited'],
            'recorded_by_user_id' => ['prohibited'],
            'payer_user_id' => ['prohibited'],
        ];
    }
}
