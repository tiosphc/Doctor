<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RecordPurchasePaymentRequest extends FormRequest
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
            'amount' => ['required', 'regex:/^[1-9][0-9]{0,14}(?:\.[0-9]{1,2})?$/'],
            'payment_method' => ['required', 'string', 'max:50'],
            'paid_at' => ['required', 'date'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
