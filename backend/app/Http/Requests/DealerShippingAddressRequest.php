<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DealerShippingAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isCustomer() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->input('province_code'));
    }

    /** @return array<string, mixed> */
    public static function rulesFor(mixed $provinceCode): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:50', 'regex:/^(?=.*[0-9])[+0-9().\-\s]+$/'],
            'address_line' => ['required', 'string', 'max:255'],
            'province_code' => ['required', Rule::exists('administrative_provinces', 'code')],
            'ward_code' => ['required', Rule::exists('administrative_wards', 'code')
                ->where('province_code', $provinceCode)],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
