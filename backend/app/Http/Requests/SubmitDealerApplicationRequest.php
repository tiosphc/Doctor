<?php

namespace App\Http\Requests;

use App\Support\CustomerIdentityNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitDealerApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isCustomer() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'company_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'business_address_line1' => ['required', 'string', 'max:255'],
            'business_address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'business_type' => ['nullable', 'string', 'max:80'],
            'estimated_monthly_purchase' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalizer = app(CustomerIdentityNormalizer::class);
        $fields = ['company_name', 'trading_name', 'contact_name', 'tax_code', 'business_address_line1', 'business_address_line2', 'city', 'province', 'country', 'postal_code', 'business_type', 'note'];
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
