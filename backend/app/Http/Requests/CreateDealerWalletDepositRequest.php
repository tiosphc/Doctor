<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\DealerContextService;
use Illuminate\Foundation\Http\FormRequest;

class CreateDealerWalletDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CUSTOMER
            && app(DealerContextService::class)->primaryAccount($this->user()) !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'between:1,1000000000000'],
            'payment_proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'dealer_account_id' => ['prohibited'],
            'dealer_wallet_id' => ['prohibited'],
        ];
    }
}
