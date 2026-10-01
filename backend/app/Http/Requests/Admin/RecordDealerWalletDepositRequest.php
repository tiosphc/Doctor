<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordDealerWalletDepositRequest extends FormRequest
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
            'method' => ['required', Rule::in(['bank_transfer', 'cash', 'other_manual'])],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'balance' => ['prohibited'],
            'currency' => ['prohibited'],
            'dealer_account_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
