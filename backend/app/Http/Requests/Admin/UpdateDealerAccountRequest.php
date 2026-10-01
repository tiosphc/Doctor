<?php

namespace App\Http\Requests\Admin;

use App\Support\CustomerIdentityNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDealerAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'billing_address_line1' => ['sometimes', 'required', 'string', 'max:255'],
            'billing_address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'province' => ['sometimes', 'required', 'string', 'max:120'],
            'country' => ['sometimes', 'required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalizer = app(CustomerIdentityNormalizer::class);
        $fields = ['legal_name', 'trading_name', 'contact_name', 'tax_code', 'billing_address_line1', 'billing_address_line2', 'city', 'province', 'country', 'postal_code'];
        $normalized = [];
        foreach ($fields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $normalized[$field] = $normalizer->normalizeName($this->input($field));
            }
        }
        if (is_string($this->input('email'))) {
            $normalized['email'] = $normalizer->normalizeEmail($this->input('email'));
        }
        if (is_string($this->input('phone'))) {
            $normalized['phone'] = $normalizer->normalizePhone($this->input('phone'));
        }
        $this->merge($normalized);
    }
}
