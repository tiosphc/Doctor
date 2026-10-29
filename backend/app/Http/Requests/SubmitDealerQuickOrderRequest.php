<?php

namespace App\Http\Requests;

class SubmitDealerQuickOrderRequest extends ReviewDealerQuickOrderRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(),
            'operation_key' => ['required', 'uuid'],
            'review_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'save_address' => ['sometimes', 'boolean'],
            'recipient_name' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'shipping_address_line1' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['nullable', 'string', 'max:255'],
            'shipping_province' => ['nullable', 'string', 'max:255'],
            'shipping_country' => ['nullable', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:30'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
